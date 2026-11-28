<?php
namespace OWA\Module\Base\Controller;
/**
 * Run whatever is due. The one command that belongs in cron.
 *
 *   * * * * * cd /path/to/owa && php cli.php cmd=schedule-run
 *
 * Every minute, matching Laravel, because the crontab line is not the schedule
 * -- it is the RESOLUTION FLOOR. A minute is the finest thing cron expresses, so
 * the floor never binds and an in-app schedule always means what it says. A
 * coarser line would let an operator configure a job for `* * * * *` and have it
 * silently run every five minutes, and configuration that quietly cannot do what
 * it says is worse than none. A boot costs tens of milliseconds.
 *
 *   cmd=schedule-run                        run everything due
 *   cmd=schedule-run --dry-run              report what would run, change nothing
 *   cmd=schedule-run job=NAME --force       run one job now, ignoring its schedule
 *   cmd=schedule-run --force-release job=X  drop a lock left by a dead process
 *   cmd=schedule-run --prune-orphans        forget jobs that are no longer registered
 *
 * --dry-run with --force-release or --prune-orphans says what would be
 * released or pruned, and changes nothing. --force needs job=.
 *
 * A no-op tick is SILENT -- debug(), never notice(). At a tick a minute, a
 * notice per tick would bury the one that matters within a week.
 *
 * @phpstan-import-type Row from \OWA\Core\Db
 * @phpstan-import-type JobRegistration from \OWA\Core\Module
 */
class ScheduleRunCli extends SchedulerCli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Runs every scheduled job that is due. The one command that belongs in cron, every minute.',
            'arguments'   => [
                '--dry-run' => [
                    'type'        => 'flag',
                    'description' => 'Report what would run, release or prune, and change nothing.',
                ],
                'job' => [
                    'type'        => 'string',
                    'value'       => '<name>',
                    'description' => 'With --force or --force-release: the job to act on.',
                ],
                '--force' => [
                    'type'        => 'flag',
                    'description' => 'With job=: run the job now, whatever its schedule.',
                ],
                '--force-release' => [
                    'type'        => 'flag',
                    'description' => 'With job=: drop a lock left by a process that died.',
                ],
                '--prune-orphans' => [
                    'type'        => 'flag',
                    'description' => 'Forget the state of jobs that are no longer registered.',
                ],
            ],
        ];
    }

    /**
     * Wall-clock budget for starting new jobs, checked BEFORE each one and
     * never mid-job. Past it, the rest stay due and run at the next tick, so
     * nothing is lost -- this only stops one tick starting a long procession of
     * heavy jobs on an instance shared with other people's sites.
     */
    const MAX_RUNTIME = 240;

    /**
     * Runs while schema updates are pending: action() then runs only the jobs
     * registered for it (core-update, which may install the release that
     * fixes them).
     */
    public function needsCurrentSchema() {

        return false;
    }

    public function action() {

        $started = time();

        $only          = $this->param( 'job' ) ?: null;
        $force         = (bool) $this->param( 'force' );
        $dry_run       = (bool) $this->param( 'dry-run' );
        $prune_orphans = (bool) $this->param( 'prune-orphans' );
        $force_release = (bool) $this->param( 'force-release' );

        if ( $force && ! $only ) {

            return $this->usageError( \OWA\Core\CoreAPI::t( '--force runs one job: name it with job=. Nothing was run.' ) );
        }

        $role = \OWA\Core\ServerRole::current();

        if ( ! $role->runsScheduler() ) {

            return $this->refuse( sprintf( 'This server (role %s) does not run the scheduler. Nothing will run.', $role->name() ) );
        }

        $jobs = $this->jobs();
        $all  = $jobs;

        // Checked once, here, rather than being discovered separately by every
        // job: Controller::doAction() intercepts each controller with the same
        // check and would render the update view once per job, recording each
        // as refused and so satisfied. The schema only: a new release not yet
        // applied leaves it as the jobs expect. Only a job registered to run
        // while updates are pending runs (core-update, which may install
        // the release that fixes them); the others stay due, unrecorded.
        $pending = \OWA\Core\CoreAPI::isSchemaUpdateRequired();

        if ( $pending ) {

            // Never with a filtered list: pruning would forget every job left out.
            $jobs = $prune_orphans || $force_release ? []
                : array_filter( $jobs, static fn ( $job ) => ! empty( $job['while_updates_pending'] ) );

            if ( ! $jobs ) {

                return $this->refuse( sprintf(
                    \OWA\Core\CoreAPI::t( 'Schema updates are pending, so only %s can run. Apply them with cmd=core-update-schema.' ),
                    implode( ', ', array_keys( array_filter( $all, static fn ( $job ) => ! empty( $job['while_updates_pending'] ) ) ) ) ?: \OWA\Core\CoreAPI::t( '(none)' )
                ) );
            }

            \OWA\Core\CoreAPI::notice( sprintf( 'Schema updates are pending: running only %s. Apply them with cmd=core-update-schema.',
                implode( ', ', array_keys( $jobs ) ) ) );
        }

        if ( $prune_orphans ) {

            $this->pruneOrphans( $jobs, $dry_run );

            return;
        }

        if ( $force_release ) {

            $this->forceRelease( $dry_run );

            return;
        }

        if ( $only && $pending && isset( $all[ $only ] ) && ! isset( $jobs[ $only ] ) ) {

            return $this->refuse( sprintf( 'Schema updates are pending, so %s cannot run. Apply them with cmd=core-update-schema.', $only ) );
        }

        if ( $only && ! isset( $jobs[ $only ] ) ) {

            return $this->usageError( sprintf(
                'No job named "%s". Registered jobs: %s.',
                $only, implode( ', ', array_keys( $jobs ) ) ?: '(none)'
            ) );
        }

        if ( $only ) {

            $jobs = [ $only => $jobs[ $only ] ];
        }

        foreach ( $jobs as $name => $job ) {

            if ( time() - $started > self::MAX_RUNTIME ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    'Runtime budget reached; %s and anything after it stay due for the next tick.', $name
                ) );

                break;
            }

            $this->considerJob( $name, $job, $force, $dry_run );
        }

        // Then the one-off jobs, in what is left of the budget.
        // Not when asked to run one named job, and not on a dry run.
        if ( ! $only && ! $dry_run && ! $pending ) {

            $this->drainQueue( $started + self::MAX_RUNTIME );
        }
    }

    /**
     * Run queued one-off jobs until the queue is empty or the deadline.
     *
     * Each through the same path as a scheduled job -- its controller built
     * directly, its outcome read the same way -- with a JobQueueLease so its
     * heartbeat() extends the job's own claim.
     *
     * @param  int $deadline
     * @return void
     */
    protected function drainQueue( $deadline ) {

        $counts = \OWA\Module\Base\Classes\JobQueue::drain( $deadline,
            function ( $command, array $params, array $row ) {

                $controller = $this->makeController( [ 'command' => $command, 'params' => $params ] );

                if ( ! $controller ) {

                    return [ 'outcome' => 'failed', 'message' => 'controller could not be constructed' ];
                }

                // The command takes only the arguments its usage() declares, as a scheduled job and cli.php.
                $undeclared = \OWA\Core\Controller\Cli::undeclaredArguments( get_class( $controller ), $params );

                if ( $undeclared ) {

                    // Final: the row's arguments are the same on every attempt.
                    return [ 'outcome' => 'failed', 'final' => true,
                             'message' => sprintf( '%s does not take %s', $command, implode( ', ', $undeclared ) ) ];
                }

                $controller->setJobLease(
                    new \OWA\Module\Base\Classes\JobQueueLease( $row['id'], $row['lease_seconds'], $row['attempts'] ) );

                \OWA\Core\CoreAPI::notice( sprintf( 'Running queued job %s (%s), attempt %d of %d.',
                    $row['id'], $command, $row['attempts'], $row['max_attempts'] ) );

                return $this->readOutcome( $controller, $this->doJobAction( $controller ) );
            } );

        if ( array_sum( $counts ) ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'Queued jobs: %d done, %d to retry, %d failed, %d gave up after dying.',
                $counts['done'], $counts['retried'], $counts['failed'], $counts['exhausted'] ) );
        }
    }

    /**
     * Decide whether one job runs, and run it.
     *
     * @param string $name
     * @param JobRegistration $job
     * @param bool   $force
     * @param bool   $dry_run
     * @return void
     */
    protected function considerJob( $name, $job, $force, $dry_run ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        // Validated first: actionNotResolved() returns null under the CLI, so an
        // unknown command would otherwise look like a silent success.
        if ( ! \OWA\Core\CoreAPI::serviceSingleton()->getCliCommandClass( $job['command'] ) ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Job "%s" names command "%s", which is not registered. Skipping it.', $name, $job['command']
            ) );

            return;
        }

        // Materialised for EVERY registered job, not only due ones. It is what
        // lets "a state row exists" stand as proof the dispatcher has run, which
        // is how schedule-status tells a missing crontab entry from a healthy
        // but idle installation. Only due for the first time in a month, a job
        // would otherwise leave nothing to look at for thirty days.
        $state = $this->ensureState( $name, $dry_run );

        if ( ! $state && ! $dry_run ) {

            // Db::query() swallows errors and returns falsy, so a failed insert
            // is silent. Without refusing here, a job whose state cannot be
            // recorded would look due at every tick and hammer the database
            // once a minute forever.
            \OWA\Core\CoreAPI::notice( sprintf(
                'Could not record state for "%s", so it will not be run.', $name
            ) );

            return;
        }

        $slot = null;

        if ( ! $force ) {

            if ( $this->isDisabled( $job ) ) {

                \OWA\Core\CoreAPI::debug( sprintf( 'Job "%s" is off.', $name ) );

                return;
            }

            $parsed = $this->parsedSchedule( $job );

            if ( $parsed === null ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    'Job "%s" has an unreadable schedule "%s", so it will not run. '
                  . 'It is deliberately not given a default -- running something on a cadence '
                  . 'nobody chose is worse than not running it.',
                    $name, $job['schedule']
                ) );

                return;
            }

            $slot = \OWA\Core\Cron::dueSlot(
                $parsed,
                $state ? (int) $state->get( 'last_run_slot' ) : 0,
                time(),
                $this->timezone()
            );

            if ( $slot === null ) {

                \OWA\Core\CoreAPI::debug( sprintf( 'Job "%s" is not due.', $name ) );

                return;
            }

            // A failed attempt at this occurrence: the next waits for its window.
            if ( $state && (string) $state->get( 'last_status' ) === 'failed' ) {

                $policy  = self::retryPolicyFor( $job );
                $attempt = \OWA\Module\Base\Classes\RetryPolicy::attemptOf( $policy, $slot, (int) $state->get( 'last_run_at' ) );

                // Spent but unanswered: the policy was tightened after the
                // attempt. The occurrence has had its attempts.
                if ( $attempt > 0 && \OWA\Module\Base\Classes\RetryPolicy::attemptsSpent( $policy, $attempt ) && ! $dry_run ) {

                    $state->set( 'last_run_slot', $slot );
                    $state->update();

                    return;
                }

                $next = $attempt > 0
                    ? \OWA\Module\Base\Classes\RetryPolicy::windowOpens( $policy, $slot, $attempt + 1 )
                    : 0;

                if ( time() < $next ) {

                    \OWA\Core\CoreAPI::debug( sprintf( 'Job "%s" failed attempt %d; attempt %d after %s.',
                        $name, $attempt, $attempt + 1, date( 'c', $next ) ) );

                    return;
                }
            }
        }

        if ( $dry_run ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Would run "%s" (%s%s).',
                $name,
                $job['command'],
                $job['params'] ? ' ' . $this->encodeParams( $job['params'] ) : ''
            ) );

            return;
        }

        $this->runJob( $name, $job, $state, $slot );
    }

    /**
     * The retry policy a registered job runs under (Classes\RetryPolicy).
     *
     * @param JobRegistration $job
     * @return array{max_attempts: int|null, backoff: list<int>}
     */
    public static function retryPolicyFor( $job ) {

        return \OWA\Module\Base\Classes\RetryPolicy::resolve(
            \OWA\Module\Base\Classes\RetryPolicy::KIND_RECURRING, (string) $job['command'], $job['retry'] ?? null );
    }

    /**
     * Whether a run's outcome answers its occurrence, so the job is next due
     * at its next scheduled time rather than retried, and what to record.
     *
     * ok and refused answer it: a refusal is an answer about the occurrence.
     * A failure leaves it due, to be retried as the job's retry policy says,
     * unless the run gave up (Cli::fail( $msg, true )) or was the policy's
     * last attempt. Either way it is still recorded as failed, and the
     * message says it is not retried.
     *
     * @param array{outcome: string, message?: string, final?: bool} $outcome
     * @param array{max_attempts: int|null, backoff: list<int>} $policy
     * @param int   $attempt  which attempt at the occurrence this was; 0 for a forced run
     * @return array{answered: bool, message: string}
     */
    public static function settle( array $outcome, array $policy, $attempt ) {

        $message = (string) ( $outcome['message'] ?? '' );

        if ( in_array( $outcome['outcome'], [ 'ok', 'refused' ], true ) ) {

            return [ 'answered' => true, 'message' => $message ];
        }

        if ( ! empty( $outcome['final'] ) ) {

            return [ 'answered' => true, 'message' => 'Gave up, not retried before the next run: ' . $message ];
        }

        if ( $attempt > 0 && \OWA\Module\Base\Classes\RetryPolicy::attemptsSpent( $policy, $attempt ) ) {

            // Not "N attempts": a run that started late lands in a later
            // window, and the windows it skipped were never tried.
            return [ 'answered' => true, 'message' => 'No attempts left, not retried before the next run: ' . $message ];
        }

        return [ 'answered' => false, 'message' => $message ];
    }

    /**
     * Take the lock, run the command, record what happened.
     *
     * @param string $name
     * @param JobRegistration $job
     * @param object $state
     * @param int|null $slot
     * @return void
     */
    protected function runJob( $name, $job, $state, $slot ) {

        $controller = $this->makeController( $job );

        if ( ! $controller ) {

            $this->record( $state, $job, 'failed', 'controller could not be constructed', time(), time(), null );

            return;
        }

        // The command takes only the arguments its usage() declares, as from cli.php.
        $undeclared = \OWA\Core\Controller\Cli::undeclaredArguments( get_class( $controller ), (array) $job['params'] );

        // Final: the arguments come from the registration, the same on every attempt.
        if ( $undeclared ) {

            $settled = self::settle( [ 'outcome' => 'failed', 'final' => true, 'message' => sprintf( '%s does not take %s',
                $job['command'], implode( ', ', $undeclared ) ) ], self::retryPolicyFor( $job ), 0 );

            $this->record( $state, $job, 'failed', $settled['message'], time(), time(), $slot );

            return;
        }

        $lease = new \OWA\Module\Base\Classes\JobLease( $name );

        // The estimate is read-only and happens BEFORE the lock is taken, so two
        // dispatchers estimating concurrently is harmless.
        if ( ! $lease->acquire( $controller->getJobLease() ) ) {

            \OWA\Core\CoreAPI::debug( sprintf( 'Job "%s" is already running; skipping this tick.', $name ) );

            return;
        }

        $controller->setJobLease( $lease );

        $began = time();

        // Written before the run, so a run that never finishes leaves
        // last_finished_at older than last_run_at for JobStatus to report.
        if ( $state ) {

            $state->set( 'last_run_at', $began );
            $state->update();
        }

        \OWA\Core\CoreAPI::notice( sprintf( 'Running "%s".', $name ) );

        try {

            $data    = $this->doJobAction( $controller );
            $outcome = $this->readOutcome( $controller, $data );

        } catch ( \OWA\Core\Controller\JobLeaseLost $lost ) {

            // Another run holds the job now and records its outcome.
            \OWA\Core\CoreAPI::notice( sprintf( 'Job "%s" stopped: %s', $name, $lost->getMessage() ) );

            return;

        } catch ( \Throwable $t ) {

            // A throw from one job is a fact about that job. Since the
            // production error handler installs a global exception handler, an
            // uncaught one would end the whole run and every job after it.
            // \Throwable rather than \Exception: a TypeError from a third-party
            // command must not take the dispatcher with it.
            $outcome = [
                'outcome' => 'failed',
                'message' => sprintf( '%s: %s', get_class( $t ), $t->getMessage() ),
            ];

            \OWA\Core\CoreAPI::notice( sprintf(
                'Job "%s" threw %s: %s', $name, get_class( $t ), $t->getMessage()
            ) );

        } finally {

            $lease->release();
        }

        $settled = self::settle( $outcome, self::retryPolicyFor( $job ),
            $slot ? \OWA\Module\Base\Classes\RetryPolicy::attemptOf( self::retryPolicyFor( $job ), $slot, $began ) : 0 );

        $this->record(
            $state,
            $job,
            $outcome['outcome'],
            $settled['message'],
            $began,
            time(),
            $settled['answered'] ? $slot : null
        );
    }

    /**
     * Run a job's controller, leaving this run's exit code as it was: a job's
     * outcome is recorded in its state, and schedule-run exits on its own.
     *
     * @param object $controller
     * @return mixed
     */
    protected function doJobAction( $controller ) {

        $exit_code = \OWA\Core\Controller\Cli::$exit_code;

        try {

            return $controller->doAction();

        } finally {

            \OWA\Core\Controller\Cli::$exit_code = $exit_code;
        }
    }

    /**
     * Construct the job's controller directly.
     *
     * Not through CoreAPI::handleRequest(), whose mergeParams() writes into the
     * shared request container -- the dispatcher's own --dry-run would become
     * every job's --dry-run. And not through performAction() either, because the
     * object is needed afterwards to read its outcome.
     *
     * @param array{command: string, params: array<mixed>} $job a JobRegistration, or a queued job's command and params
     * @return object|null
     */
    protected function makeController( $job ) {

        $s      = \OWA\Core\CoreAPI::serviceSingleton();
        $action = $s->getCliCommandClass( $job['command'] );
        $map    = $s->getMapValue( 'actions', $action );

        if ( ! $map ) {

            return null;
        }

        try {

            return \OWA\Core\Lib::simpleFactory( $map['class_name'], '', (array) $job['params'] );

        } catch ( \Throwable $t ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Could not construct %s: %s', $job['command'], $t->getMessage()
            ) );

            return null;
        }
    }

    /**
     * What the command reported, allowing for commands that report nothing.
     *
     * @param object $controller
     * @param mixed  $data
     * @return array{outcome: string, message: string}
     */
    protected function readOutcome( $controller, $data ) {

        $data = is_array( $data ) ? $data : [];

        // doAction() intercepts on its own account before reaching action().
        if ( isset( $data['view'] ) && $data['view'] === 'base.error' ) {

            return [ 'outcome' => 'failed', 'message' => 'capability check failed' ];
        }

        if ( isset( $data['view'] ) && $data['view'] === 'base.genericCli' ) {

            return [ 'outcome' => 'refused', 'message' => 'schema updates are pending' ];
        }

        if ( method_exists( $controller, 'getCliOutcome' ) ) {

            $outcome = $controller->getCliOutcome();

            return [
                'outcome' => isset( $outcome['outcome'] ) ? $outcome['outcome'] : 'ok',
                'message' => isset( $outcome['message'] ) ? $outcome['message'] : '',
                'final'   => ! empty( $outcome['final'] ),
            ];
        }

        return [ 'outcome' => 'ok', 'message' => '' ];
    }

    /**
     * Create the state row if it is missing, and hand it back.
     *
     * @param string $name
     * @param bool   $dry_run
     * @return object|null
     */
    protected function ensureState( $name, $dry_run = false ) {

        $state = $this->state( $name );

        if ( $state || $dry_run ) {

            return $state;
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.scheduled_job' );

        $entity->set( 'id', $entity->generateId( $name ) );
        $entity->set( 'job_name', $name );
        $entity->set( 'last_status', 'never run' );
        $entity->set( 'last_message', '-' );
        $entity->set( 'last_params', '-' );
        $entity->create();

        // Read back rather than trusting the insert: query() returns falsy on
        // error without raising, so "it worked" has to be confirmed.
        return $this->state( $name );
    }

    /**
     * Write the outcome of a run.
     *
     * Only monotonic values and non-empty strings are ever written, because
     * Entity::set() silently drops falsy ones -- which is also why there is no
     * consecutive-failures counter to reset.
     *
     * @param object   $state
     * @param JobRegistration $job
     * @param string   $outcome
     * @param string   $message
     * @param int      $began
     * @param int      $ended
     * @param int|null $slot
     * @return void
     */
    protected function record( $state, $job, $outcome, $message, $began, $ended, $slot ) {

        if ( ! $state ) {

            return;
        }

        $state->set( 'last_run_at', $began );
        $state->set( 'last_finished_at', $ended );
        $state->set( 'last_duration', max( 1, $ended - $began ) );
        $state->set( 'last_status', $outcome );
        $state->set( 'last_message', substr( $message ?: '-', 0, 250 ) );
        $state->set( 'last_params', $this->encodeParams( $job['params'] ) );
        $state->set( 'run_count', (int) $state->get( 'run_count' ) + 1 );

        if ( $slot ) {

            $state->set( 'last_run_slot', $slot );
        }

        if ( $outcome === 'failed' ) {

            $state->set( 'last_failure_at', $ended );
            $state->set( 'failure_count', (int) $state->get( 'failure_count' ) + 1 );

        } else {

            $state->set( 'last_success_at', $ended );
        }

        $state->update();

        \OWA\Core\CoreAPI::notice( sprintf(
            'Job "%s" finished: %s%s (%ds).',
            $state->get( 'job_name' ),
            $outcome,
            $message ? ' -- ' . $message : '',
            max( 1, $ended - $began )
        ) );
    }

    /**
     * Forget state for jobs that are no longer registered.
     *
     * Never automatic. A typo in OWA_SCHEDULED_JOBS de-registers a real job, and
     * auto-pruning would then delete its entire run record -- turning an obvious
     * mistake into an unrecoverable one. Deactivating a module is usually
     * temporary too, and losing last_run_slot makes the job read as never-run
     * when it comes back.
     *
     * @param array<string, JobRegistration> $jobs
     * @param bool $dry_run say what would be pruned, and prune nothing
     * @return void
     */
    protected function pruneOrphans( $jobs, $dry_run = false ) {

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $pruned  = 0;

        foreach ( $this->allState() as $name => $row ) {

            if ( isset( $jobs[ $name ] ) ) {

                continue;
            }

            $pruned++;

            if ( $dry_run ) {

                \OWA\Core\CoreAPI::notice( sprintf( \OWA\Core\CoreAPI::t( 'Would prune orphaned job "%s".' ), $name ) );

                continue;
            }

            \OWA\Core\CoreAPI::entityFactory( 'base.scheduled_job' )->delete( $name, 'job_name' );
            $db->releaseJobLock( $name, $row['owner'] ?? '' );
            $db->deleteJobLock( $name, time() + 86400 * 3650 );

            \OWA\Core\CoreAPI::notice( sprintf( 'Pruned orphaned job "%s".', $name ) );
        }

        \OWA\Core\CoreAPI::notice( $dry_run
            ? sprintf( \OWA\Core\CoreAPI::tn( '%d orphaned job would be pruned. Nothing was changed.', '%d orphaned jobs would be pruned. Nothing was changed.', $pruned ), $pruned )
            : sprintf( '%d orphaned job%s pruned.', $pruned, $pruned === 1 ? '' : 's' ) );
    }

    /**
     * Drop a lock left behind by a process that died.
     *
     * The deliberate answer to "this lock is stuck and I want it back now",
     * which is the real need behind wanting a shorter lease -- and a far better
     * one, since it carries no standing risk of a duplicate run.
     *
     * @param bool $dry_run say what would be released, and release nothing
     * @return void
     */
    protected function forceRelease( $dry_run = false ) {

        $name = $this->param( 'job' );

        if ( ! $name ) {

            $this->usageError( 'Say which job: cmd=schedule-run --force-release job=NAME' );

            return;
        }

        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $lock = $db->getJobLock( $name );

        if ( ! $lock ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'No lock held for "%s".', $name ) );

            return;
        }

        if ( $dry_run ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                \OWA\Core\CoreAPI::t( 'Would release the lock on "%1$s", held since %2$s by %3$s. Nothing was changed.' ),
                $name, $this->readable( $lock['acquired_at'] ), $lock['owner'] ) );

            return;
        }

        $db->releaseJobLock( $name, $lock['owner'] );

        \OWA\Core\CoreAPI::notice( sprintf(
            'Released the lock on "%s", held since %s. If that job is in fact still running, '
          . 'it may now run twice.',
            $name, $this->readable( $lock['acquired_at'] )
        ) );
    }
}
