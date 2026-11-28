<?php
namespace OWA\Module\Base\Controller;
/**
 * What the scheduler is doing, and why a job is not running.
 *
 *   php cli.php cmd=schedule-status
 *
 * Read-only. NEVER WRITES A ROW -- if it materialised state it would manufacture
 * the very evidence it is being read for: run it once before cron has ever
 * fired, and it would report that cron had fired.
 *
 * Saying only "this job is behind" makes the reader guess. This walks an ordered
 * list of causes and names the first that holds, which works because every way a
 * LIVE dispatcher can leave a job behind is directly observable -- so eliminating
 * them makes "the scheduler is not running" a conclusion rather than a shrug.
 *
 * Nothing here alerts; a human has to run it. Real alerting is the ping-hook and
 * mail-on-failure route, deliberately out of scope for now.
 *
 * @phpstan-import-type Row from \OWA\Core\Db
 * @phpstan-import-type JobRegistration from \OWA\Core\Module
 * @phpstan-import-type CronFields from \OWA\Core\Cron
 * @phpstan-import-type Section from \OWA\Module\Base\Classes\SystemHealth
 */
class ScheduleStatusCli extends SchedulerCli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Reports what the scheduler is doing and, for a job that is behind, the first cause that explains it. Read-only.',
            'arguments'   => [
                'format=markdown' => 'Print the registered jobs as a Markdown table instead.',
            ],
        ];
    }

    /** See JobStatus::MIN_GRACE. */
    const MIN_GRACE = \OWA\Module\Base\Classes\JobStatus::MIN_GRACE;

    public function action() {

        if ( $this->getParam( 'format' ) === 'markdown' ) {

            $this->write( $this->markdown() );

            return;
        }

        $now     = time();
        $jobs    = $this->jobs();
        $state   = $this->allState();
        $pending = \OWA\Core\CoreAPI::isSchemaUpdateRequired();

        $lines = [ sprintf(
            'Scheduler status, %s (%s). %d job%s registered.',
            $this->readable( $now ), $this->timezone(), count( $jobs ), \OWA\Core\Lib::plural( count( $jobs ), '', 's' )
        ) ];

        // One source for this string, shared with the admin banner and the
        // install page -- an installation told two different cron lines depending
        // on which screen it read has to guess which one is right.
        $lines[] = 'Expected cron entry:  '
                 . \OWA\Module\Base\Classes\SchedulerHealth::cronLine();

        $role = \OWA\Core\ServerRole::current();

        if ( ! $role->runsScheduler() ) {

            $lines[] = '';
            $lines[] = sprintf( 'This server (role %s) does not run the scheduler: the full server runs these jobs.',
                $role->name() );
        }

        if ( $pending ) {

            $lines[] = '';
            $lines[] = 'ACTION: schema updates are pending, so the dispatcher refuses every job. '
                     . 'Apply them with cmd=core-update-schema. Nothing below will run until you do.';
        }

        if ( ! $pending && \OWA\Core\CoreAPI::isUpdateRequired() ) {

            $lines[] = '';
            $lines[] = 'NOTE: an update is pending that changes no schema -- a new tracker. Jobs still run; the admin '
                     . 'screens wait for the update, and Profiles\' tracking bundles are from the last one. Apply it with cmd=core-update-schema.';
        }

        // Any state row is proof the dispatcher has run at least once, because
        // it is the only thing that writes them. That is what separates "cron
        // was never installed" from "healthy, nothing due yet".
        $ever = (bool) $state;
        $last_activity = 0;

        foreach ( $state as $row ) {

            $last_activity = max( $last_activity, (int) ( $row['last_run_at'] ?? 0 ) );
        }

        foreach ( $jobs as $name => $job ) {

            $lines[] = '';
            $lines   = array_merge( $lines, $this->describeJob(
                $name, $job, $state[ $name ] ?? null, $now, $pending, $ever, $last_activity
            ) );
        }

        $lines = array_merge( $lines, $this->describeOrphans( $jobs, $state ) );
        $lines = array_merge( $lines, $this->describeQueue() );
        $lines = array_merge( $lines, $this->describeIntake() );
        $lines = array_merge( $lines, $this->summarise( $jobs, $state, $ever, $last_activity, $now ) );

        $this->write( $lines );
    }

    /**
     * This install's jobs as a Markdown table:
     *
     *   php cli.php cmd=schedule-status format=markdown
     *
     * The schedule is this install's: spread jobs land on a minute derived from
     * it, and OWA_SCHEDULED_JOBS is applied. The wiki's table is the same one
     * over the registrations alone, with spread schedules described as spread
     * (tests/tools/wiki/sections/jobs.php).
     *
     * @return string[]
     */
    protected function markdown() {

        return self::markdownTable( $this->jobs(), function ( $job ) {
            return $this->isDisabled( $job ) ? 'off' : \OWA\Core\Cron::describe( $job['schedule'] );
        } );
    }

    /**
     * A job's retry policy in words (Classes\RetryPolicy::describe()).
     *
     * @param JobRegistration $job
     * @return string
     */
    public static function retriesInWords( $job ) {

        return \OWA\Module\Base\Classes\RetryPolicy::describe(
            \OWA\Module\Base\Controller\ScheduleRunCli::retryPolicyFor( $job ),
            \OWA\Module\Base\Classes\RetryPolicy::KIND_RECURRING );
    }

    /**
     * Jobs as a Markdown table: name, the command line it runs, the schedule
     * in words as $schedule gives it, the description, and its retries.
     *
     * @param array<string, JobRegistration> $jobs keyed by name
     * @param callable $schedule  job => the schedule in words
     * @return string[]
     */
    public static function markdownTable( array $jobs, callable $schedule ) {

        $lines = [ '| Job | Runs | Schedule | What it does | Retries |', '|---|---|---|---|---|' ];
        $cell  = fn ( $v ) => str_replace( [ '|', "\n" ], [ '\\|', ' ' ], (string) $v );

        foreach ( $jobs as $name => $job ) {

            $args = [ $job['command'] ];

            foreach ( (array) ( $job['params'] ?? [] ) as $k => $v ) {
                $args[] = $k . '=' . $v;
            }

            $lines[] = sprintf( '| `%s` | `%s` | %s | %s | %s |', $name, $cell( implode( ' ', $args ) ),
                $cell( $schedule( $job ) ), $cell( $job['description'] ?? '' ), $cell( self::retriesInWords( $job ) ) );
        }

        return $lines;
    }

    /**
     * The one-off job queue, drained after the recurring jobs:
     * its counts, and what Classes\SystemHealth finds wrong with it -- the
     * same findings the System Health screen shows.
     *
     * @return string[]
     */
    protected function describeQueue() {

        $section = \OWA\Module\Base\Classes\SystemHealth::queue();
        $q       = $section['stats'];

        $lines = [ '', 'Queued jobs' ];

        $lines[] = sprintf( '  due %d, delayed %d, running %d, failed %d, done %d',
            $q['due'], $q['delayed'], $q['running'], $q['failed'], $q['done'] );

        return array_merge( $lines, self::problemLines( $section ) );
    }

    /**
     * The tracker-ingest intake: only when something is
     * wrong -- a backlog, dead letters, a drain that has hung.
     *
     * @return string[]
     */
    protected function describeIntake() {

        $lines = self::problemLines( \OWA\Module\Base\Classes\SystemHealth::intake() );

        return $lines ? array_merge( [ '', 'Tracker ingest' ], $lines ) : [];
    }

    /**
     * A section's findings that are not green, as status lines.
     *
     * @param  Section $section
     * @return list<string>
     */
    private static function problemLines( array $section ) {

        $lines = [];

        foreach ( $section['findings'] as $f ) {

            if ( $f['level'] === 'green' ) {

                continue;
            }

            $lines[] = sprintf( '  %s: %s: %s', $f['level'] === 'red' ? 'WARNING' : 'NOTE', $f['label'], $f['detail'] );

            if ( $f['command'] ) {

                $lines[] = '    ' . $f['command'];
            }
        }

        return $lines;
    }

    /**
     * One job's block.
     *
     * @param string          $name
     * @param JobRegistration $job
     * @param Row|null        $row  its state row
     * @param int             $now
     * @param bool            $pending  a schema update is pending
     * @param bool            $ever  any job has a state row
     * @param int             $last_activity  the latest last_run_at of any job
     * @return string[]
     */
    protected function describeJob( $name, $job, $row, $now, $pending, $ever, $last_activity ) {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $lock   = $db->getJobLock( $name );
        $parsed = $this->parsedSchedule( $job );

        $lines = [ sprintf(
            '%s    %s (%s)',
            $name,
            $this->isDisabled( $job ) ? 'off' : \OWA\Core\Cron::describe( $job['schedule'] ),
            $job['source']
        ) ];

        if ( ( $job['description'] ?? '' ) !== '' ) {

            $lines[] = '  does         ' . $job['description'];
        }

        $lines[] = sprintf( '  runs         %s%s',
            $job['command'],
            $job['params'] ? ' ' . $this->encodeParams( $job['params'] ) : ' (no arguments)'
        );

        $lines[] = '  retries      ' . self::retriesInWords( $job );

        if ( $parsed ) {

            $next = \OWA\Core\Cron::nextAfter( $parsed, $now, $this->timezone() );

            $lines[] = sprintf( '  next due     %s', $this->readable( $next, 'unknown' ) );
        }

        if ( ! $row ) {

            $lines[] = '  last run     never';

        } else {

            $lines[] = sprintf( '  last run     %s, %s%s',
                $this->readable( $row['last_run_at'] ),
                $row['last_status'] ?: 'unknown',
                ( $row['last_duration'] ?? 0 ) ? sprintf( ' (%ds)', (int) $row['last_duration'] ) : ''
            );

            if ( ! empty( $row['last_message'] ) && $row['last_message'] !== '-' ) {

                $lines[] = sprintf( '               %s', $row['last_message'] );
            }

            $runs = (int) ( $row['run_count'] ?? 0 );

            $lines[] = sprintf( '  history      %d run%s, %d failed',
                $runs, $runs === 1 ? '' : 's', (int) ( $row['failure_count'] ?? 0 ) );

            // The arguments a run used are recorded, so a change is visible
            // BEFORE it takes effect rather than diagnosed afterwards.
            $current = $this->encodeParams( $job['params'] );

            if ( ! empty( $row['last_params'] ) && $row['last_params'] !== $current ) {

                $lines[] = sprintf(
                    '  NOTE         arguments have changed since the last run: was "%s", now "%s".',
                    $row['last_params'], $current
                );
            }
        }

        $reason = $this->diagnose( $name, $job, $row, $lock, $parsed, $now, $pending, $ever, $last_activity );

        if ( $reason ) {

            $lines[] = '  ACTION       ' . $reason;
        }

        return $lines;
    }

    /**
     * Why is this job not running? See JobStatus::diagnose(), which the Reporting
     * Cube screen asks too, so the two cannot tell different stories.
     *
     * @param string          $name
     * @param JobRegistration $job
     * @param Row|null        $row  its state row
     * @param Row|null        $lock  its lock row
     * @param CronFields|null $parsed  its schedule
     * @param int             $now
     * @param bool            $pending  a schema update is pending
     * @param bool            $ever  any job has a state row
     * @param int             $last_activity  the latest last_run_at of any job
     * @return string|null
     */
    protected function diagnose( $name, $job, $row, $lock, $parsed, $now, $pending, $ever, $last_activity ) {

        return \OWA\Module\Base\Classes\JobStatus::diagnose(
            $name, $job, $row, $lock, $parsed, $now, $pending, $ever, $last_activity );
    }

    /**
     * @param int $seconds
     * @return string
     */
    protected function howLate( $seconds ) {

        return \OWA\Module\Base\Classes\JobStatus::howLate( $seconds );
    }

    /**
     * State rows for jobs nothing registers any more.
     *
     * Kept rather than deleted, so a job that vanished through a config typo is
     * visible instead of silently gone.
     *
     * @param array<string, JobRegistration> $jobs
     * @param array<int|string, Row> $state
     * @return string[]
     */
    protected function describeOrphans( $jobs, $state ) {

        $orphans = array_diff_key( $state, $jobs );

        if ( ! $orphans ) {

            return [];
        }

        $lines = [ '', sprintf( 'Orphaned (%d) -- no longer registered, never run, kept for their history:', count( $orphans ) ) ];

        foreach ( $orphans as $name => $row ) {

            $lines[] = sprintf( '  %-28s last run %s, %s',
                $name, $this->readable( $row['last_run_at'] ), $row['last_status'] ?: 'unknown' );
        }

        $lines[] = '  Remove them with cmd=schedule-run --prune-orphans (never automatic: a typo in';
        $lines[] = '  OWA_SCHEDULED_JOBS de-registers a real job, and auto-pruning would delete its history).';

        return $lines;
    }

    /**
     * @param array<string, JobRegistration> $jobs
     * @param array<int|string, Row> $state
     * @param bool $ever  any job has a state row
     * @param int  $last_activity  the latest last_run_at of any job
     * @param int  $now
     * @return string[]
     */
    protected function summarise( $jobs, $state, $ever, $last_activity, $now ) {

        $lines = [ '' ];

        if ( ! $jobs ) {

            $lines[] = 'No jobs are registered.';

            return $lines;
        }

        if ( ! $ever ) {

            $lines[] = 'The dispatcher has never run. Until the cron entry above is in place, nothing here happens.';

            return $lines;
        }

        $lines[] = sprintf(
            '%d job%s registered. Last activity of any kind: %s.',
            count( $jobs ), \OWA\Core\Lib::plural( count( $jobs ), '', 's' ), $this->readable( $last_activity )
        );

        // Queued beacons are ingested only by drain-tracker-ingest.
        if ( \OWA\Module\Base\Classes\TrackerIngest::isQueued() ) {

            $drains = false;

            foreach ( $jobs as $job ) {

                if ( $job['command'] === 'drain-tracker-ingest' && ! $this->isDisabled( $job ) ) {

                    $drains = true;
                }
            }

            if ( ! $drains ) {

                $lines[] = 'NOTE: beacons are queued (OWA_QUEUE_TRACKING_EVENTS) but no job runs drain-tracker-ingest, '
                         . 'so nothing ingests them. See OWA_SCHEDULED_JOBS in owa-config.php.';
            }
        }

        return $lines;
    }
}
