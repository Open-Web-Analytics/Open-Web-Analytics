<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Is the installation's background work happening (PLAN 2.30.5): the
 * scheduler, its recurring jobs, the one-off job queue and the tracker-ingest
 * intake, each as findings with a level.
 *
 * One source for the System Health screen and cmd=schedule-status, so the
 * two say the same thing about the same state. A finding is
 *
 *   level    'red' (someone must act), 'yellow' (time or the next run will
 *            resolve it, or it is worth a look), 'green'
 *   label    what it is about
 *   detail   what is true, in a sentence
 *   command  what to run about it, or null
 *
 * and a section's level is its worst finding's.
 */
class SystemHealth {

    /** A queued job due this long and not started: the scheduler is not draining the queue. */
    const QUEUE_DUE_TOO_LONG = 300;

    /** The intake's oldest due beacon older than this: the drain is not keeping up. */
    const INTAKE_BACKLOG_AGE = 600;

    /** A tracker-ingest batch held longer than this: its drain is hung (a scheduled one stops after 45 s). */
    const INTAKE_HELD_TOO_LONG = 600;

    /** How many failed jobs the screen lists. */
    const FAILED_SHOWN = 20;

    /**
     * Every section, in the order the screen shows them.
     *
     * @return array[] key => [title, level, findings[], ...section data]
     */
    public static function sections() {

        return array(
            'scheduler' => self::scheduler(),
            'jobs'      => self::recurringJobs(),
            'queue'     => self::queue(),
            'intake'    => self::intake(),
        );
    }

    /** The worst of some levels. */
    public static function worst( array $levels ) {

        foreach ( array( 'red', 'yellow' ) as $level ) {

            if ( in_array( $level, $levels, true ) ) {

                return $level;
            }
        }

        return 'green';
    }

    private static function section( $title, array $findings, array $extra = array() ) {

        return array_merge( array(
            'title'    => $title,
            'level'    => self::worst( array_column( $findings, 'level' ) ),
            'findings' => $findings,
        ), $extra );
    }

    private static function finding( $level, $label, $detail, $command = null ) {

        return array( 'level' => $level, 'label' => $label, 'detail' => $detail, 'command' => $command );
    }

    // ---------------------------------------------------------------------
    // The sections
    // ---------------------------------------------------------------------

    /** Is cron running schedule-run, and may it run jobs. */
    public static function scheduler() {

        $findings = array();

        if ( ! \OWA\Core\CoreAPI::getSetting( 'base', 'scheduler_enabled' ) ) {

            $findings[] = self::finding( 'red', 'Disabled',
                'OWA_SCHEDULER_ENABLED turns the scheduler off, so no job runs.' );
        }

        $problem = SchedulerHealth::problem();

        if ( $problem ) {

            $findings[] = self::finding( 'red', (string) $problem['headline'], (string) $problem['message'],
                SchedulerHealth::cronLine() );
        }

        if ( \OWA\Core\CoreAPI::isSchemaUpdateRequired() ) {

            $findings[] = self::finding( 'red', 'Schema updates pending',
                'Every job is refused until they are applied.', 'php cli.php cmd=update' );

        } elseif ( \OWA\Core\CoreAPI::isUpdateRequired() ) {

            $findings[] = self::finding( 'yellow', 'Update pending',
                'An update that changes no schema -- a new tracker -- is waiting. Jobs still run.',
                'php cli.php cmd=update' );
        }

        if ( ! $findings ) {

            $findings[] = self::finding( 'green', 'Running', 'cron is running schedule-run.' );
        }

        return self::section( 'Scheduler', $findings );
    }

    /** Each registered recurring job: when it last ran and how it went, and why it is behind if it is. */
    public static function recurringJobs() {

        $rows = array();

        foreach ( array_keys( JobStatus::jobs() ) as $name ) {

            $s   = JobStatus::forJob( $name );
            $row = (array) $s['row'];

            $level = $s['reason'] ? 'yellow' : 'green';

            if ( ! $s['running'] && ( $row['last_status'] ?? '' ) === 'failed' ) {

                $level = 'red';
            }

            $rows[] = array(
                'name'     => $name,
                'level'    => $level,
                'schedule' => JobStatus::isDisabled( $s['job'] ) ? 'off' : \OWA\Core\Cron::describe( $s['job']['schedule'] ),
                'last_run' => isset( $row['last_run_at'] ) ? JobStatus::readable( (int) $row['last_run_at'] ) : 'never',
                'outcome'  => $s['running'] ? 'running' : (string) ( $row['last_status'] ?? '' ),
                'message'  => (string) ( $row['last_message'] ?? '' ),
                'next'     => $s['next'] ? JobStatus::readable( (int) $s['next'] ) : '',
                'reason'   => (string) ( $s['reason'] ?? '' ),
            );
        }

        $findings = array_map( fn ( $r ) => self::finding( $r['level'], $r['name'],
            $r['reason'] ?: ( $r['outcome'] === 'failed' ? 'Its last run failed: ' . $r['message'] : '' ) ), $rows );

        $findings = array_values( array_filter( $findings, fn ( $f ) => $f['level'] !== 'green' ) )
            ?: array( self::finding( 'green', 'Up to date', 'Every job ran when it was due.' ) );

        return self::section( 'Recurring jobs', $findings, array( 'rows' => $rows ) );
    }

    /** The one-off job queue: what is waiting, running and failed, and whether it is being drained. */
    public static function queue() {

        $stats    = JobQueue::stats();
        $findings = array();

        if ( $stats['oldest_due_age'] !== null && $stats['oldest_due_age'] > self::QUEUE_DUE_TOO_LONG ) {

            $findings[] = self::finding( 'red', 'Not draining', sprintf(
                'A job has been due for %d minutes: the scheduler is not draining the queue.',
                intdiv( $stats['oldest_due_age'], 60 ) ), 'php cli.php cmd=schedule-status' );
        }

        if ( $stats['failed'] ) {

            $findings[] = self::finding( 'yellow', 'Failed jobs', sprintf(
                '%d job(s) used up their attempts and are kept with their errors.', $stats['failed'] ),
                'php cli.php cmd=jobs-retry id=<id>   (or id=all)' );
        }

        if ( ! $findings ) {

            $findings[] = self::finding( 'green', 'Draining', 'Nothing is waiting longer than it should.' );
        }

        return self::section( 'Job queue', $findings, array(
            'stats'  => $stats,
            'failed' => $stats['failed'] ? JobQueue::listJobs( 'failed', self::FAILED_SHOWN ) : array(),
        ) );
    }

    /** The tracker-ingest intake: beacons waiting, dead letters, and any drain that has hung. */
    public static function intake() {

        try {

            $intake = TrackerIngest::queue();
            $main   = $intake->stats();
            $dlq    = $intake->deadLetterQueue() ? $intake->deadLetterQueue()->stats() : null;
            $held   = method_exists( $intake, 'heldBatches' ) ? $intake->heldBatches() : array();

        } catch ( \Throwable $t ) {

            return self::section( 'Tracker ingest',
                array( self::finding( 'red', 'Unavailable', $t->getMessage() ) ) );
        }

        $findings = array();

        if ( $main['oldest_age'] !== null && $main['oldest_age'] > self::INTAKE_BACKLOG_AGE ) {

            $findings[] = self::finding( 'red', 'Backlog', sprintf(
                '%s beacon(s) waiting, the oldest for %d minutes: the drain is not keeping up%s.',
                $main['messages'] ?? 'Some', intdiv( $main['oldest_age'], 60 ),
                TrackerIngest::isDrainedExternally() ? ' (tracker_ingest_drain is external)' : '' ),
                'php cli.php cmd=schedule-status' );
        }

        if ( $dlq && $dlq['messages'] ) {

            $findings[] = self::finding( 'yellow', 'Dead letters', sprintf(
                '%d beacon(s) in the dead-letter queue%s. Fix the cause, then replay them; '
                . 'replay-tracker-ingest sends each back once a day on its own.',
                $dlq['messages'],
                $dlq['oldest_age'] !== null ? ', the oldest ' . intdiv( $dlq['oldest_age'], 3600 ) . ' hours old' : '' ),
                'php cli.php cmd=tracker-ingest-replay' );
        }

        foreach ( $held as $batch ) {

            $age = $batch['held_since'] === null ? null : time() - $batch['held_since'];

            if ( $age !== null && $age < self::INTAKE_HELD_TOO_LONG ) {

                continue;
            }

            $findings[] = self::finding( 'red', 'Hung drain', sprintf(
                'Batch %s has been held for %s by process %s%s. If that process is hung, end it and the next '
                . 'drain takes the batch over.',
                $batch['batch'], $age === null ? 'an unknown time' : intdiv( $age, 60 ) . ' minutes',
                $batch['pid'] === null ? '(unknown)' : $batch['pid'],
                $batch['host'] !== '' ? ' on ' . $batch['host'] : '' ) );
        }

        if ( ! $findings ) {

            $findings[] = self::finding( 'green', TrackerIngest::isQueued() ? 'Draining' : 'Direct',
                TrackerIngest::isQueued()
                    ? 'Beacons are queued and nothing is waiting longer than it should.'
                    : 'Beacons are ingested as they arrive; a failed write is retried from the intake.' );
        }

        return self::section( 'Tracker ingest', $findings, array(
            'type'     => (string) ( \OWA\Core\CoreAPI::getSetting( 'base', 'tracker_ingest_queue_type' ) ?: 'file' ),
            'queued'   => TrackerIngest::isQueued(),
            'waiting'  => $main['messages'],
            'dead'     => $dlq ? $dlq['messages'] : null,
        ) );
    }
}

?>
