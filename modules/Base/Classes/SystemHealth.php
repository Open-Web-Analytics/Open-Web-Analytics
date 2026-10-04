<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Is the installation doing what it should (PLAN 2.30.5): the scheduler,
 * its recurring jobs, the one-off job queue, the tracker-ingest intake, the
 * Profiles' tracking bundles, and the installation itself -- its versions and
 * the environment checks the installer runs -- each as findings with a level.
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
            'bundles'   => self::bundles(),
            'data'      => self::data(),
            'install'   => self::installation(),
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
                'description' => (string) ( $s['job']['description'] ?? '' ),
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

    /**
     * The Profiles' tracking bundles (PLAN 2.24, 2.30.7): built, published
     * and current, and served so browsers revalidate them.
     */
    public static function bundles() {

        $findings = array();
        $rows     = array();

        if ( ! TrackerBundle::buildManifest() ) {

            return self::section( 'Tracker bundles', array( self::finding( 'red', 'Not built',
                'The tracker has not been built on this installation, so no bundle can be published.',
                'npm run build' ) ) );
        }

        // A Profile's own name is numbered within its Property ("Observation
        // Profile 1"), so the Property's name is what tells them apart.
        $names      = array();
        $properties = array();

        foreach ( (array) \OWA\Core\CoreAPI::getSitesList() as $site ) {

            $property_id = (string) ( $site['property_id'] ?? '' );

            if ( $property_id !== '' && ! isset( $properties[ $property_id ] ) ) {

                $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
                $property->load( $property_id );
                $properties[ $property_id ] = $property->wasPersisted() ? (string) $property->get( 'name' ) : '';
            }

            $names[ (string) $site['site_id'] ] = trim( ( $properties[ $property_id ] ?? '' ) . ' / '
                . (string) ( $site['name'] ?? '' ), ' /' );
        }

        $stale = 0;

        foreach ( TrackerBundle::siteIds() as $site_id ) {

            $status = TrackerBundle::status( $site_id );
            $stale += $status['state'] === 'published' ? 0 : 1;

            $rows[] = array(
                'site_id'      => $site_id,
                'name'         => $names[ $site_id ] ?? '',
                'level'        => $status['state'] === 'published' ? 'green' : 'yellow',
                'state'        => $status['state'] === 'published' ? 'current' : ( $status['published_at'] ? 'stale' : 'not published' ),
                'published_at' => $status['published_at'] ? JobStatus::readable( (int) $status['published_at'] ) : '',
            );
        }

        $required = \OWA\Module\Base\Module::requiredTrackerVersion();
        $recorded = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'tracker_version' );

        if ( $recorded < $required ) {

            $findings[] = self::finding( 'yellow', 'New tracker', sprintf(
                'This code builds tracker version %d and the installation was last updated with %d: the update '
                . 'republishes every bundle.', $required, $recorded ), 'php cli.php cmd=update' );
        }

        if ( $stale ) {

            $queued = JobQueue::isQueued( 'publish-trackers' );

            $findings[] = self::finding( 'yellow', 'Not current', sprintf(
                '%d Profile(s) have a bundle that is stale or not published%s.', $stale,
                $queued ? '; a publish is queued' : '' ), $queued ? null : 'php cli.php cmd=publish-trackers' );
        }

        $cache = (array) \OWA\Core\CoreAPI::getSetting( 'base', 'tracker_cache_headers' );

        if ( array_key_exists( 'ok', $cache ) && $cache['ok'] === false ) {

            $findings[] = self::finding( 'yellow', 'Cached by browsers', sprintf(
                'Bundles are served with Cache-Control "%s", so a browser may keep an old one after a change. '
                . 'OWA\'s .htaccess sets no-cache for them when Apache reads .htaccess (AllowOverride) and has '
                . 'mod_headers or mod_expires.',
                (string) ( $cache['cache_control'] ?? '' ) ) );

        } elseif ( array_key_exists( 'ok', $cache ) && $cache['ok'] === null ) {

            $findings[] = self::finding( 'yellow', 'Cache header unknown', sprintf(
                'Fetching %s answered %s, so what browsers are told is unknown.',
                (string) ( $cache['url'] ?? 'a bundle' ),
                ! empty( $cache['status'] ) ? 'HTTP ' . $cache['status'] : 'nothing' ) );
        }

        if ( ! $findings ) {

            $findings[] = self::finding( 'green', 'Current', $rows
                ? 'Every Profile\'s bundle is published from this build and its settings.'
                : 'No web Profile yet.' );
        }

        return self::section( 'Tracker bundles', $findings, array( 'rows' => $rows ) );
    }

    /**
     * What the installation holds and how long it keeps it.
     *
     * Raw's size is the server's own estimate (Db::tableSize()) rather than
     * counted: a COUNT(*) over the event table is a full scan. It is
     * approximate and can be a day old, so the screen says "about".
     */
    public static function data() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $count = function ( $entity, $active_only ) use ( $db ) {
            $table = \OWA\Core\CoreAPI::entityFactory( $entity )->getTableName();
            $row   = $db->get_row( sprintf( 'SELECT COUNT(*) AS n FROM %s%s', $table,
                $active_only ? ' WHERE archived_date IS NULL OR archived_date = 0' : '' ) );
            return (int) ( $row['n'] ?? 0 );
        };

        $facts = array(
            'Organizations' => (string) $count( 'base.organization', false ),
            'Properties'    => (string) $count( 'base.property', true ),
            'Profiles'      => (string) $count( 'base.site', true ),
        );

        // Raw: the estimate and size, and the oldest partition holding rows.
        $raw   = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
        $size = $db->tableSize( $raw );

        $facts['Raw events'] = $size
            ? sprintf( 'about %s, %s on disk', number_format( $size['rows'] ), self::bytes( $size['bytes'] ) )
            : 'unknown';

        foreach ( (array) $db->listPartitions( $raw ) as $partition ) {

            if ( $partition['rows'] > 0 && preg_match( '/^p(\d{4})(\d{2})(\d{2})$/', $partition['name'], $m ) ) {

                $facts['Oldest data'] = sprintf( 'in the partition that starts %s-%s-%s', $m[1], $m[2], $m[3] );

                break;
            }
        }

        // Retention: the Data Retention settings, applied by rotate-partitions.
        $rotate = JobStatus::jobs()['rotate-partitions'] ?? null;
        $keep   = Retention::rawMonths();

        $facts['Retention'] = $keep
            ? sprintf( 'events older than %d months are deleted by rotate-partitions (Data Retention settings)', $keep )
            : 'nothing is deleted: event data is kept indefinitely (Data Retention settings)';

        $facts['Fine partitions'] = sprintf( 'the last %d months by month, older years by year',
            (int) \OWA\Core\CoreAPI::getSetting( 'base', 'partition_detail_months' ) );

        $findings = array();

        if ( ! $rotate || JobStatus::isDisabled( $rotate ) ) {

            $findings[] = self::finding( 'yellow', 'Not rotated', 'rotate-partitions is not running, so new periods '
                . 'get no partitions of their own and retention is not applied.' );
        }

        if ( ! $findings ) {

            $findings[] = self::finding( 'green', 'Rotated', 'rotate-partitions is running.' );
        }

        return self::section( 'Data', $findings, array( 'facts' => $facts ) );
    }

    private static function bytes( $n ) {

        foreach ( array( 'GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024 ) as $unit => $size ) {

            if ( $n >= $size ) {

                return sprintf( '%.1f %s', $n / $size, $unit );
            }
        }

        return $n . ' bytes';
    }

    /**
     * The installation itself: what is running, and the environment checks
     * the installer runs (Classes\EnvironmentCheck, shared with cmd=instance-info).
     */
    public static function installation() {

        $base = \OWA\Core\CoreAPI::serviceSingleton()->getModule( 'base' );

        $facts = array(
            'OWA'     => (string) OWA_VERSION,
            'PHP'     => PHP_VERSION . ' (' . PHP_SAPI . ')',
            'Schema'  => sprintf( '%d (this code requires %d)',
                (int) \OWA\Core\CoreAPI::getSetting( 'base', 'schema_version' ), (int) $base->required_schema_version ),
            'Tracker' => sprintf( 'version %d (this code builds %d)',
                (int) \OWA\Core\CoreAPI::getSetting( 'base', 'tracker_version' ),
                \OWA\Module\Base\Module::requiredTrackerVersion() ),
        );

        $findings = array();

        foreach ( EnvironmentCheck::all( (string) \OWA\Core\CoreAPI::getSetting( 'base', 'config_file' ) ) as $check ) {

            $findings[] = self::finding( $check['passed'] ? 'green' : 'red', (string) $check['name'],
                (string) $check['value'] . ( $check['passed'] ? '' : '. ' . $check['msg'] ) );
        }

        return self::section( 'Installation', $findings, array( 'facts' => $facts ) );
    }
}

?>
