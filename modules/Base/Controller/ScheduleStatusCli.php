<?php
namespace OWA\Module\Base\Controller;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
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
 */
class ScheduleStatusCli extends SchedulerCli {

    /** See JobStatus::MIN_GRACE. */
    const MIN_GRACE = \OWA\Module\Base\Classes\JobStatus::MIN_GRACE;

    function action() {

        $now     = time();
        $jobs    = $this->jobs();
        $state   = $this->allState();
        $enabled = (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'scheduler_enabled' );
        $pending = \OWA\Core\CoreAPI::isSchemaUpdateRequired();

        $lines = array( sprintf(
            'Scheduler status, %s (%s). %d job(s) registered.',
            $this->readable( $now ), $this->timezone(), count( $jobs )
        ) );

        // One source for this string, shared with the admin banner and the
        // install page -- an installation told two different cron lines depending
        // on which screen it read has to guess which one is right.
        $lines[] = 'Expected cron entry:  '
                 . \OWA\Module\Base\Classes\SchedulerHealth::cronLine();

        if ( ! $enabled ) {

            $lines[] = '';
            $lines[] = 'The scheduler is DISABLED by OWA_SCHEDULER_ENABLED. No job will run.';
        }

        if ( $pending ) {

            $lines[] = '';
            $lines[] = 'ACTION: schema updates are pending, so the dispatcher refuses every job. '
                     . 'Apply them with cmd=update. Nothing below will run until you do.';
        }

        if ( ! $pending && \OWA\Core\CoreAPI::isUpdateRequired() ) {

            $lines[] = '';
            $lines[] = 'NOTE: this release has not been applied yet. Jobs still run; the admin screens wait for '
                     . 'the update, and Profiles\' tracking bundles are from the last one. Apply it with cmd=update.';
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
                $name, $job, $state[ $name ] ?? null, $now, $enabled, $pending, $ever, $last_activity
            ) );
        }

        $lines = array_merge( $lines, $this->describeOrphans( $jobs, $state ) );
        $lines = array_merge( $lines, $this->describeQueue() );
        $lines = array_merge( $lines, $this->describeIntake() );
        $lines = array_merge( $lines, $this->summarise( $jobs, $state, $ever, $last_activity, $now ) );

        $this->write( $lines );
    }

    /**
     * The one-off job queue (PLAN 2.30.5), drained after the recurring jobs.
     *
     * @return string[]
     */
    protected function describeQueue() {

        $q = \OWA\Module\Base\Classes\JobQueue::stats();

        $lines = array( '', 'Queued jobs' );

        $lines[] = sprintf( '  due %d, delayed %d, running %d, failed %d, done %d',
            $q['due'], $q['delayed'], $q['running'], $q['failed'], $q['done'] );

        if ( $q['oldest_due_age'] !== null && $q['oldest_due_age'] > 300 ) {

            $lines[] = sprintf( '  WARNING: a job has been due for %d minutes; the scheduler is not draining the queue.',
                intdiv( $q['oldest_due_age'], 60 ) );
        }

        if ( $q['failed'] ) {

            $lines[] = '  See them with cmd=jobs status=failed; retry with cmd=jobs-retry id=<id>|all.';
        }

        return $lines;
    }

    /**
     * A tracker-ingest batch held longer than this is reported as hung: a
     * scheduled drain stops after DrainTrackerIngestCli::BUDGET seconds.
     */
    const INTAKE_HELD_TOO_LONG = 600;

    /** The main queue's oldest beacon older than this means the drain is not keeping up. */
    const INTAKE_BACKLOG_AGE = 600;

    /**
     * The tracker-ingest intake (PLAN 2.30.3, 2.30.4): what is waiting in it
     * and its dead-letter queue, and any batch a drain has held too long.
     *
     * A drain that dies releases its batch to the next one; a drain that
     * hangs -- a database call that never returns -- holds it until the
     * process ends, and nothing else would say so. That part is only for an
     * intake that can tell (the file queue's heldBatches()).
     *
     * @return string[]
     */
    protected function describeIntake() {

        try {

            $intake = \OWA\Module\Base\Classes\TrackerIngest::queue();
            $main   = $intake->stats();
            $dlq    = $intake->deadLetterQueue() ? $intake->deadLetterQueue()->stats() : null;

        } catch ( \Throwable $t ) {

            return array( '', 'Tracker ingest', '  WARNING: ' . $t->getMessage() );
        }

        $lines = array();
        $now   = time();

        if ( $main['oldest_age'] !== null && $main['oldest_age'] > self::INTAKE_BACKLOG_AGE ) {

            $lines[] = sprintf( '  WARNING: %s beacon(s) waiting, the oldest for %d minutes; the drain is not keeping up%s.',
                $main['messages'] ?? 'some', intdiv( $main['oldest_age'], 60 ),
                \OWA\Module\Base\Classes\TrackerIngest::isDrainedExternally() ? ' (tracker_ingest_drain is external)' : '' );
        }

        if ( $dlq && $dlq['messages'] ) {

            $lines[] = sprintf( '  WARNING: %d beacon(s) in the dead-letter queue%s. Fix the cause, then '
                              . 'cmd=tracker-ingest-replay; replay-tracker-ingest sends each back once a day on its own.',
                $dlq['messages'],
                $dlq['oldest_age'] !== null ? ', the oldest ' . intdiv( $dlq['oldest_age'], 3600 ) . ' hours old' : '' );
        }

        foreach ( method_exists( $intake, 'heldBatches' ) ? $intake->heldBatches() : array() as $held ) {

            $age = $held['held_since'] === null ? null : $now - $held['held_since'];

            if ( $age !== null && $age < self::INTAKE_HELD_TOO_LONG ) {

                continue;
            }

            $lines[] = sprintf( '  WARNING: batch %s has been held for %s by process %s%s; '
                              . 'if that process is hung, end it and the next drain takes the batch over.',
                $held['batch'],
                $age === null ? 'an unknown time' : intdiv( $age, 60 ) . ' minutes',
                $held['pid'] === null ? '(unknown)' : $held['pid'],
                $held['host'] !== '' ? ' on ' . $held['host'] : '' );
        }

        return $lines ? array_merge( array( '', 'Tracker ingest' ), $lines ) : array();
    }

    /**
     * One job's block.
     *
     * @return string[]
     */
    protected function describeJob( $name, $job, $row, $now, $enabled, $pending, $ever, $last_activity ) {

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $lock   = $db->getJobLock( $name );
        $parsed = $this->parsedSchedule( $job );

        $lines = array( sprintf(
            '%s    %s (%s)',
            $name,
            $this->isDisabled( $job ) ? 'off' : \OWA\Core\Cron::describe( $job['schedule'] ),
            $job['source']
        ) );

        $lines[] = sprintf( '  runs         %s%s',
            $job['command'],
            $job['params'] ? ' ' . $this->encodeParams( $job['params'] ) : ' (no arguments)'
        );

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

            $lines[] = sprintf( '  history      %d run(s), %d failed',
                (int) ( $row['run_count'] ?? 0 ), (int) ( $row['failure_count'] ?? 0 ) );

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

        $reason = $this->diagnose( $name, $job, $row, $lock, $parsed, $now, $enabled, $pending, $ever, $last_activity );

        if ( $reason ) {

            $lines[] = '  ACTION       ' . $reason;
        }

        return $lines;
    }

    /**
     * Why is this job not running? See JobStatus::diagnose(), which the Reporting
     * Cube screen asks too, so the two cannot tell different stories.
     *
     * @return string|null
     */
    protected function diagnose( $name, $job, $row, $lock, $parsed, $now, $enabled, $pending, $ever, $last_activity ) {

        return \OWA\Module\Base\Classes\JobStatus::diagnose(
            $name, $job, $row, $lock, $parsed, $now, $enabled, $pending, $ever, $last_activity );
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
     * @return string[]
     */
    protected function describeOrphans( $jobs, $state ) {

        $orphans = array_diff_key( $state, $jobs );

        if ( ! $orphans ) {

            return array();
        }

        $lines = array( '', sprintf( 'Orphaned (%d) -- no longer registered, never run, kept for their history:', count( $orphans ) ) );

        foreach ( $orphans as $name => $row ) {

            $lines[] = sprintf( '  %-28s last run %s, %s',
                $name, $this->readable( $row['last_run_at'] ), $row['last_status'] ?: 'unknown' );
        }

        $lines[] = '  Remove them with cmd=schedule-run --prune-orphans (never automatic: a typo in';
        $lines[] = '  OWA_SCHEDULED_JOBS de-registers a real job, and auto-pruning would delete its history).';

        return $lines;
    }

    /**
     * @return string[]
     */
    protected function summarise( $jobs, $state, $ever, $last_activity, $now ) {

        $lines = array( '' );

        if ( ! $jobs ) {

            $lines[] = 'No jobs are registered.';

            return $lines;
        }

        if ( ! $ever ) {

            $lines[] = 'The dispatcher has never run. Until the cron entry above is in place, nothing here happens.';

            return $lines;
        }

        $lines[] = sprintf(
            '%d job(s) registered. Last activity of any kind: %s.',
            count( $jobs ), $this->readable( $last_activity )
        );

        // Queued beacons are ingested only by drain-tracker-ingest, or by
        // whatever consumes the queue when tracker_ingest_drain is external.
        if ( \OWA\Module\Base\Classes\TrackerIngest::isQueued()
             && ! \OWA\Module\Base\Classes\TrackerIngest::isDrainedExternally() ) {

            $drains = false;

            foreach ( $jobs as $job ) {

                if ( $job['command'] === 'drain-tracker-ingest' && ! $this->isDisabled( $job ) ) {

                    $drains = true;
                }
            }

            if ( ! $drains ) {

                $lines[] = 'NOTE: beacons are queued (queue_tracker_ingest) but no job runs drain-tracker-ingest, '
                         . 'so nothing ingests them. See OWA_SCHEDULED_JOBS in owa-config.php.';
            }
        }

        return $lines;
    }
}
