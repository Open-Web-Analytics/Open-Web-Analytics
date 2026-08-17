<?php
namespace OWA\Module\Base\Controller;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Run whatever is due. The one command that belongs in cron.
 *
 *   * * * * * php /path/to/owa/cli.php cmd=schedule-run
 *
 * Every minute, matching Laravel, because the crontab line is not the schedule
 * -- it is the RESOLUTION FLOOR. A minute is the finest thing cron expresses, so
 * the floor never binds and an in-app schedule always means what it says. A
 * coarser line would let an operator configure a job for `* * * * *` and have it
 * silently run every five minutes, and configuration that quietly cannot do what
 * it says is worse than none. A boot costs about 66ms, so the whole day's ticks
 * are around 95 seconds of CPU.
 *
 *   cmd=schedule-run                        run everything due
 *   cmd=schedule-run --dry-run              report what would run, change nothing
 *   cmd=schedule-run job=NAME --force       run one job now, ignoring its schedule
 *   cmd=schedule-run --force-release job=X  drop a lock left by a dead process
 *   cmd=schedule-run --prune-orphans        forget jobs that are no longer registered
 *
 * A no-op tick is SILENT -- debug(), never notice(). At a tick a minute, a
 * notice per tick would bury the one that matters within a week.
 */
class ScheduleRunCli extends SchedulerCli {

    /**
     * Wall-clock budget for starting new jobs, checked BEFORE each one and
     * never mid-job. Past it, the rest stay due and run at the next tick, so
     * nothing is lost -- this only stops one tick starting a long procession of
     * heavy jobs on an instance shared with other people's sites.
     */
    const MAX_RUNTIME = 240;

    function action() {

        $started = time();

        if ( ! \OWA\Core\CoreAPI::getSetting( 'base', 'scheduler_enabled' ) ) {

            return $this->refuse( 'The scheduler is disabled (OWA_SCHEDULER_ENABLED). Nothing will run.' );
        }

        // Checked once, here, rather than being discovered separately by every
        // job: Controller::doAction() intercepts each controller with the same
        // check and would render the update view once per job.
        if ( \OWA\Core\CoreAPI::isUpdateRequired() ) {

            return $this->refuse(
                'Schema updates are pending, so no job can run. Apply them with cmd=update.'
            );
        }

        $jobs = $this->jobs();

        if ( $this->getParam( 'prune-orphans' ) ) {

            $this->pruneOrphans( $jobs );

            return;
        }

        if ( $this->getParam( 'force-release' ) ) {

            $this->forceRelease();

            return;
        }

        $only    = $this->getParam( 'job' ) ?: null;
        $force   = (bool) $this->getParam( 'force' );
        $dry_run = (bool) $this->getParam( 'dry-run' );

        if ( $only && ! isset( $jobs[ $only ] ) ) {

            return $this->refuse( sprintf(
                'No job named "%s". Registered jobs: %s.',
                $only, implode( ', ', array_keys( $jobs ) ) ?: '(none)'
            ) );
        }

        if ( $only ) {

            $jobs = array( $only => $jobs[ $only ] );
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
    }

    /**
     * Decide whether one job runs, and run it.
     *
     * @param string $name
     * @param array  $job
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
     * Take the lock, run the command, record what happened.
     *
     * @param string $name
     * @param array  $job
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

        $lease = new \OWA\Module\Base\Classes\JobLease( $name );

        // The estimate is read-only and happens BEFORE the lock is taken, so two
        // dispatchers estimating concurrently is harmless.
        if ( ! $lease->acquire( $controller->getJobLease() ) ) {

            \OWA\Core\CoreAPI::debug( sprintf( 'Job "%s" is already running; skipping this tick.', $name ) );

            return;
        }

        $controller->setJobLease( $lease );

        $began = time();

        \OWA\Core\CoreAPI::notice( sprintf( 'Running "%s".', $name ) );

        try {

            $data    = $controller->doAction();
            $outcome = $this->readOutcome( $controller, $data );

        } catch ( \Throwable $t ) {

            // A throw from one job is a fact about that job. Since the
            // production error handler installs a global exception handler, an
            // uncaught one would end the whole run and every job after it.
            // \Throwable rather than \Exception: a TypeError from a third-party
            // command must not take the dispatcher with it.
            $outcome = array(
                'outcome' => 'failed',
                'message' => sprintf( '%s: %s', get_class( $t ), $t->getMessage() ),
            );

            \OWA\Core\CoreAPI::notice( sprintf(
                'Job "%s" threw %s: %s', $name, get_class( $t ), $t->getMessage()
            ) );

        } finally {

            $lease->release();
        }

        $this->record(
            $state,
            $job,
            $outcome['outcome'],
            $outcome['message'],
            $began,
            time(),
            // Only ok and refused satisfy the occurrence. A refusal is an answer
            // about it; a failure is not, so the job stays due and is retried.
            in_array( $outcome['outcome'], array( 'ok', 'refused' ), true ) ? $slot : null
        );
    }

    /**
     * Construct the job's controller directly.
     *
     * Not through CoreAPI::handleRequest(), whose mergeParams() writes into the
     * shared request container -- the dispatcher's own --dry-run would become
     * every job's --dry-run. And not through performAction() either, because the
     * object is needed afterwards to read its outcome.
     *
     * @param array $job
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

            return \OWA\Core\Lib::simpleFactory( $map['class_name'], $map['file'], (array) $job['params'] );

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
     * @return array
     */
    protected function readOutcome( $controller, $data ) {

        $data = is_array( $data ) ? $data : array();

        // doAction() intercepts on its own account before reaching action().
        if ( isset( $data['view'] ) && $data['view'] === 'base.error' ) {

            return array( 'outcome' => 'failed', 'message' => 'capability check failed' );
        }

        if ( isset( $data['view'] ) && $data['view'] === 'base.genericCli' ) {

            return array( 'outcome' => 'refused', 'message' => 'schema updates are pending' );
        }

        if ( method_exists( $controller, 'getCliOutcome' ) ) {

            $outcome = $controller->getCliOutcome();

            return array(
                'outcome' => isset( $outcome['outcome'] ) ? $outcome['outcome'] : 'ok',
                'message' => isset( $outcome['message'] ) ? $outcome['message'] : '',
            );
        }

        return array( 'outcome' => 'ok', 'message' => '' );
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
     * @param array    $job
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
     * @param array $jobs
     * @return void
     */
    protected function pruneOrphans( $jobs ) {

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $pruned  = 0;

        foreach ( $this->allState() as $name => $row ) {

            if ( isset( $jobs[ $name ] ) ) {

                continue;
            }

            \OWA\Core\CoreAPI::entityFactory( 'base.scheduled_job' )->delete( $name, 'job_name' );
            $db->releaseJobLock( $name, $row['owner'] ?? '' );
            $db->deleteJobLock( $name, time() + 86400 * 3650 );

            \OWA\Core\CoreAPI::notice( sprintf( 'Pruned orphaned job "%s".', $name ) );

            $pruned++;
        }

        \OWA\Core\CoreAPI::notice( sprintf( '%d orphaned job(s) pruned.', $pruned ) );
    }

    /**
     * Drop a lock left behind by a process that died.
     *
     * The deliberate answer to "this lock is stuck and I want it back now",
     * which is the real need behind wanting a shorter lease -- and a far better
     * one, since it carries no standing risk of a duplicate run.
     *
     * @return void
     */
    protected function forceRelease() {

        $name = $this->getParam( 'job' );

        if ( ! $name ) {

            return $this->refuse( 'Say which job: cmd=schedule-run --force-release job=NAME' );
        }

        $db   = \OWA\Core\CoreAPI::dbSingleton();
        $lock = $db->getJobLock( $name );

        if ( ! $lock ) {

            \OWA\Core\CoreAPI::notice( sprintf( 'No lock held for "%s".', $name ) );

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
