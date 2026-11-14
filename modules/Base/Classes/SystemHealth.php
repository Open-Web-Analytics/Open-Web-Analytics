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

        $problem = SchedulerHealth::problem();

        if ( $problem ) {

            $findings[] = self::finding( 'red', (string) $problem['headline'], (string) $problem['message'],
                SchedulerHealth::cronLine() );
        }

        /*
         * A pending update, schema or not, is not a finding here. While one is
         * pending every admin screen, this one included, shows the updater
         * instead. cmd=schedule-status reports it.
         */

        if ( ! $findings ) {

            $findings[] = self::finding( 'green', \OWA\Core\CoreAPI::t( 'Running' ), \OWA\Core\CoreAPI::t( 'cron is running schedule-run.' ) );
        }

        return self::section( \OWA\Core\CoreAPI::t( 'Scheduler' ), $findings );
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
            $r['reason'] ?: ( $r['outcome'] === 'failed' ? sprintf( \OWA\Core\CoreAPI::t( 'Its last run failed: %s' ), $r['message'] ) : '' ) ), $rows );

        $findings = array_values( array_filter( $findings, fn ( $f ) => $f['level'] !== 'green' ) )
            ?: array( self::finding( 'green', \OWA\Core\CoreAPI::t( 'Up to date' ), \OWA\Core\CoreAPI::t( 'Every job ran when it was due.' ) ) );

        return self::section( \OWA\Core\CoreAPI::t( 'Recurring jobs' ), $findings, array( 'rows' => $rows ) );
    }

    /** The one-off job queue: what is waiting, running and failed, and whether it is being drained. */
    public static function queue() {

        $stats    = JobQueue::stats();
        $findings = array();

        if ( $stats['oldest_due_age'] !== null && $stats['oldest_due_age'] > self::QUEUE_DUE_TOO_LONG ) {

            $findings[] = self::finding( 'red', \OWA\Core\CoreAPI::t( 'Not draining' ), sprintf(
                \OWA\Core\CoreAPI::tn( 'A job has been due for %d minute: the scheduler is not draining the queue.',
                    'A job has been due for %d minutes: the scheduler is not draining the queue.', intdiv( $stats['oldest_due_age'], 60 ) ),
                intdiv( $stats['oldest_due_age'], 60 ) ), 'php cli.php cmd=schedule-status' );
        }

        if ( $stats['failed'] ) {

            $findings[] = self::finding( 'yellow', \OWA\Core\CoreAPI::t( 'Failed jobs' ), sprintf( \OWA\Core\CoreAPI::tn(
                '%d job used up its attempts and is kept with its errors.',
                '%d jobs used up their attempts and are kept with their errors.', $stats['failed'] ), $stats['failed'] ),
                'php cli.php cmd=jobs-retry id=<id>   (or id=all)' );
        }

        if ( ! $findings ) {

            $findings[] = self::finding( 'green', \OWA\Core\CoreAPI::t( 'Draining' ), \OWA\Core\CoreAPI::t( 'Nothing is waiting longer than it should.' ) );
        }

        return self::section( \OWA\Core\CoreAPI::t( 'Job queue' ), $findings, array(
            'stats'  => $stats,
            'failed' => $stats['failed'] ? JobQueue::listJobs( 'failed', self::FAILED_SHOWN ) : array(),
        ) );
    }

    /** The tracker-ingest intake: beacons waiting, dead letters, and any drain that has hung. */
    public static function intake() {

        try {

            $intake   = TrackerIngest::queue();
            $main     = $intake->stats();
            $dlq      = $intake->deadLetterQueue() ? $intake->deadLetterQueue()->stats() : null;
            $held     = method_exists( $intake, 'heldBatches' ) ? $intake->heldBatches() : array();
            $fallback = TrackerIngest::fallback();
            $kept     = $fallback ? $fallback->stats() : null;

        } catch ( \Throwable $t ) {

            return self::section( \OWA\Core\CoreAPI::t( 'Tracker ingest' ),
                array( self::finding( 'red', \OWA\Core\CoreAPI::t( 'Unavailable' ), $t->getMessage() ) ) );
        }

        $findings = array();

        // A queue that cannot be read cannot be drained either, and says nothing about a backlog.
        if ( $main['messages'] === null ) {

            $findings[] = self::finding( 'red', \OWA\Core\CoreAPI::t( 'Unreadable' ), sprintf(
                \OWA\Core\CoreAPI::t( 'The tracker-ingest queue could not be read%s, so nothing is being drained from it.' ),
                ! empty( $main['error'] ) ? ': ' . $main['error'] : '' ),
                'php cli.php cmd=drain-tracker-ingest' );
        }

        if ( $kept && $kept['messages'] ) {

            $findings[] = self::finding( 'yellow', \OWA\Core\CoreAPI::t( 'Kept in the file queue' ), sprintf( \OWA\Core\CoreAPI::tn(
                '%d beacon the tracker-ingest queue did not take is kept in the file queue. drain-tracker-ingest forwards it once the queue answers.',
                '%d beacons the tracker-ingest queue did not take are kept in the file queue. drain-tracker-ingest forwards them once the queue answers.',
                (int) $kept['messages'] ), (int) $kept['messages'] ),
                'php cli.php cmd=drain-tracker-ingest' );
        }

        if ( $main['oldest_age'] !== null && $main['oldest_age'] > self::INTAKE_BACKLOG_AGE ) {

            // A queue that cannot count its messages says "Some", a plural.
            $findings[] = self::finding( 'red', \OWA\Core\CoreAPI::t( 'Backlog' ), sprintf( \OWA\Core\CoreAPI::tn(
                '%s beacon waiting, the oldest for %d minutes: the drain is not keeping up.',
                '%s beacons waiting, the oldest for %d minutes: the drain is not keeping up.', $main['messages'] ?? 2 ),
                $main['messages'] ?? \OWA\Core\CoreAPI::t( 'Some' ), intdiv( $main['oldest_age'], 60 ) ),
                'php cli.php cmd=schedule-status' );
        }

        if ( $dlq && $dlq['messages'] ) {

            $findings[] = self::finding( 'yellow', \OWA\Core\CoreAPI::t( 'Dead letters' ), sprintf( \OWA\Core\CoreAPI::tn(
                '%d beacon in the dead-letter queue%s. Fix the cause, then replay it; '
                . 'replay-tracker-ingest sends it back once a day on its own.',
                '%d beacons in the dead-letter queue%s. Fix the cause, then replay them; '
                . 'replay-tracker-ingest sends each back once a day on its own.', $dlq['messages'] ),
                $dlq['messages'],
                $dlq['oldest_age'] !== null ? sprintf( \OWA\Core\CoreAPI::tn( ', the oldest %d hour old', ', the oldest %d hours old',
                    intdiv( $dlq['oldest_age'], 3600 ) ), intdiv( $dlq['oldest_age'], 3600 ) ) : '' ),
                'php cli.php cmd=tracker-ingest-replay' );
        }

        foreach ( $held as $batch ) {

            $age = $batch['held_since'] === null ? null : time() - $batch['held_since'];

            if ( $age !== null && $age < self::INTAKE_HELD_TOO_LONG ) {

                continue;
            }

            $findings[] = self::finding( 'red', \OWA\Core\CoreAPI::t( 'Hung drain' ), sprintf(
                \OWA\Core\CoreAPI::t( 'Batch %s has been held for %s by process %s%s. If that process is hung, end it and the next '
                . 'drain takes the batch over.' ),
                $batch['batch'], $age === null ? \OWA\Core\CoreAPI::t( 'an unknown time' )
                    : sprintf( \OWA\Core\CoreAPI::tn( '%d minute', '%d minutes', intdiv( $age, 60 ) ), intdiv( $age, 60 ) ),
                $batch['pid'] === null ? \OWA\Core\CoreAPI::t( '(unknown)' ) : $batch['pid'],
                $batch['host'] !== '' ? ' on ' . $batch['host'] : '' ) );
        }

        $backend = TrackerIngest::backend();

        /*
         * A queue backend configured while beacons are written as they arrive:
         * right for a worker that only drains it, worth saying on a server
         * that receives beacons.
         */
        if ( $backend !== 'file' && ! TrackerIngest::isQueued()
             && \OWA\Core\ServerRole::current()->serves( \OWA\Core\RequestType::TRACKING ) ) {

            $findings[] = self::finding( 'green',
                sprintf( \OWA\Core\CoreAPI::t( '%s configured, tracking events not queued' ), strtoupper( $backend ) ),
                \OWA\Core\CoreAPI::t( 'Beacons are written as they arrive. Only a beacon whose write fails goes to the queue, '
                    . 'to be retried. OWA_QUEUE_TRACKING_EVENTS queues every beacon.' ) );
        }

        if ( ! $findings ) {

            $findings[] = self::finding( 'green',
                TrackerIngest::isQueued() ? \OWA\Core\CoreAPI::t( 'Draining' ) : \OWA\Core\CoreAPI::t( 'Direct' ),
                TrackerIngest::isQueued()
                    ? \OWA\Core\CoreAPI::t( 'Beacons are queued and nothing is waiting longer than it should.' )
                    : \OWA\Core\CoreAPI::t( 'Beacons are written as they arrive. Only a beacon whose write fails is queued, '
                        . 'and retried a minute later.' ) );
        }

        $blocked = self::spamBlocked();

        if ( $blocked ) {

            $findings[] = $blocked;
        }

        return self::section( \OWA\Core\CoreAPI::t( 'Tracker ingest' ), $findings, array(
            'type'     => $backend,
            'queued'   => TrackerIngest::isQueued(),
            'waiting'  => $main['messages'],
            'dead'     => $dlq ? $dlq['messages'] : null,
            'kept'     => $kept ? $kept['messages'] : null,
        ) );
    }

    /**
     * Beacons spam detection blocked today and yesterday, in total and by rule
     * (owa_spam_block, Classes\Spam\Blocks), as a green finding: blocking is
     * the setting working. Null when it blocked none.
     *
     * Today and yesterday rather than 24 hours, because the table counts by
     * day, derived as raw's yyyymmdd is.
     *
     * @return array|null
     */
    public static function spamBlocked() {

        $yesterday = (int) date( 'Ymd', strtotime( '-1 day', time() ) );

        $counts = \OWA\Module\Base\Classes\Spam\Blocks::since( $yesterday );
        $total  = (int) ( $counts[ \OWA\Module\Base\Entity\SpamBlock::TOTAL ] ?? 0 );

        if ( ! $total ) {

            return null;
        }

        unset( $counts[ \OWA\Module\Base\Entity\SpamBlock::TOTAL ] );

        $rules = array();

        foreach ( $counts as $rule => $n ) {

            $rules[] = sprintf( '%s %d', $rule, $n );
        }

        return self::finding( 'green', \OWA\Core\CoreAPI::t( 'Blocked as spam' ), sprintf(
            \OWA\Core\CoreAPI::tn( '%1$d beacon today and yesterday. By rule: %2$s.',
                '%1$d beacons today and yesterday. By rule: %2$s.', $total ),
            $total, $rules ? implode( ', ', $rules ) : '-' ) );
    }

    /**
     * The Profiles' tracking bundles (PLAN 2.24, 2.30.7): built, published
     * and current, and served so browsers revalidate them.
     */
    public static function bundles() {

        $findings = array();
        $rows     = array();

        if ( ! TrackerBundle::buildManifest() ) {

            return self::section( \OWA\Core\CoreAPI::t( 'Tracker bundles' ), array( self::finding( 'red', \OWA\Core\CoreAPI::t( 'Not built' ),
                \OWA\Core\CoreAPI::t( 'The tracker has not been built on this installation, so no bundle can be published.' ),
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

        /*
         * A built tracker newer than the recorded one is not a finding here.
         * It makes an update pending, and while one is pending every admin
         * screen, this one included, shows the updater instead.
         * cmd=schedule-status reports it.
         */
        if ( $stale ) {

            $queued = JobQueue::isQueued( 'publish-trackers' );

            $findings[] = self::finding( 'yellow', \OWA\Core\CoreAPI::t( 'Not current' ), sprintf( \OWA\Core\CoreAPI::tn(
                '%d Profile has a bundle that is stale or not published%s.',
                '%d Profiles have a bundle that is stale or not published%s.', $stale ), $stale,
                $queued ? '; a publish is queued' : '' ), $queued ? null : 'php cli.php cmd=publish-trackers' );
        }

        $cache = (array) \OWA\Core\CoreAPI::getSetting( 'base', 'tracker_cache_headers' );

        if ( array_key_exists( 'ok', $cache ) && $cache['ok'] === false ) {

            $findings[] = self::finding( 'yellow', \OWA\Core\CoreAPI::t( 'Cached by browsers' ), sprintf(
                \OWA\Core\CoreAPI::t( 'Bundles are served with Cache-Control "%s", so a browser may keep an old one after a change. '
                . 'OWA\'s .htaccess sets no-cache for them when Apache reads .htaccess (AllowOverride) and has '
                . 'mod_headers or mod_expires.' ),
                (string) ( $cache['cache_control'] ?? '' ) ) );

        } elseif ( array_key_exists( 'ok', $cache ) && $cache['ok'] === null ) {

            $findings[] = self::finding( 'yellow', \OWA\Core\CoreAPI::t( 'Cache header unknown' ), sprintf(
                \OWA\Core\CoreAPI::t( 'Fetching %s answered %s, so what browsers are told is unknown.' ),
                (string) ( $cache['url'] ?? 'a bundle' ),
                ! empty( $cache['status'] ) ? 'HTTP ' . $cache['status'] : 'nothing' ) );
        }

        if ( ! $findings ) {

            $findings[] = self::finding( 'green', \OWA\Core\CoreAPI::t( 'Current' ), $rows
                ? 'Every Profile\'s bundle is published from this build and its settings.'
                : 'No web Profile yet.' );
        }

        return self::section( \OWA\Core\CoreAPI::t( 'Tracker bundles' ), $findings, array( 'rows' => $rows ) );
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
            \OWA\Core\CoreAPI::t( 'Organizations' ) => (string) $count( 'base.organization', false ),
            \OWA\Core\CoreAPI::t( 'Properties' )    => (string) $count( 'base.property', true ),
            \OWA\Core\CoreAPI::t( 'Profiles' )      => (string) $count( 'base.site', true ),
        );

        // Raw: the estimate and size, and the oldest partition holding rows.
        $raw   = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
        $size = $db->tableSize( $raw );

        $facts[ \OWA\Core\CoreAPI::t( 'Raw events' ) ] = $size
            ? sprintf( \OWA\Core\CoreAPI::t( 'about %1$s, %2$s on disk' ), \OWA\Core\Strings::formatNumber( $size['rows'] ), self::bytes( $size['bytes'] ) )
            : \OWA\Core\CoreAPI::t( 'unknown' );

        foreach ( (array) $db->listPartitions( $raw ) as $partition ) {

            if ( $partition['rows'] > 0 && preg_match( '/^p(\d{4})(\d{2})(\d{2})$/', $partition['name'], $m ) ) {

                $facts[ \OWA\Core\CoreAPI::t( 'Oldest data' ) ] = sprintf( \OWA\Core\CoreAPI::t( 'in the partition that starts %s-%s-%s' ), $m[1], $m[2], $m[3] );

                break;
            }
        }

        // Retention: the Data Retention settings, applied by rotate-partitions.
        $rotate = JobStatus::jobs()['rotate-partitions'] ?? null;
        $keep   = Retention::rawMonths();

        $facts[ \OWA\Core\CoreAPI::t( 'Retention' ) ] = $keep
            ? sprintf( \OWA\Core\CoreAPI::tn( 'events older than %d month are deleted by rotate-partitions (Data Retention settings)',
                'events older than %d months are deleted by rotate-partitions (Data Retention settings)', $keep ), $keep )
            : \OWA\Core\CoreAPI::t( 'nothing is deleted: event data is kept indefinitely (Data Retention settings)' );

        $detail = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'partition_detail_months' );

        $facts[ \OWA\Core\CoreAPI::t( 'Fine partitions' ) ] = sprintf( \OWA\Core\CoreAPI::tn( 'the last %d month by month, older years by year',
            'the last %d months by month, older years by year', $detail ), $detail );

        $findings = array();

        if ( ! $rotate || JobStatus::isDisabled( $rotate ) ) {

            $findings[] = self::finding( 'yellow', \OWA\Core\CoreAPI::t( 'Not rotated' ), \OWA\Core\CoreAPI::t( 'rotate-partitions is not running, so new periods '
                . 'get no partitions of their own and retention is not applied.' ) );
        }

        if ( ! $findings ) {

            $findings[] = self::finding( 'green', \OWA\Core\CoreAPI::t( 'Rotated' ), \OWA\Core\CoreAPI::t( 'rotate-partitions is running.' ) );
        }

        return self::section( \OWA\Core\CoreAPI::t( 'Data' ), $findings, array( 'facts' => $facts ) );
    }

    private static function bytes( $n ) {

        foreach ( array( 'GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024 ) as $unit => $size ) {

            if ( $n >= $size ) {

                return sprintf( '%.1f %s', $n / $size, $unit );
            }
        }

        return sprintf( \OWA\Core\CoreAPI::tn( '%d byte', '%d bytes', $n ), $n );
    }

    /** A build hash, short enough to read; 'none' for no build or none recorded. */
    private static function shortBuild( $hash ) {

        return $hash === '' ? \OWA\Core\CoreAPI::t( 'none' ) : substr( $hash, 0, 12 );
    }

    /**
     * The installation itself: what is running, and the environment checks
     * the installer runs (Classes\EnvironmentCheck, shared with cmd=instance-info).
     */
    public static function installation() {

        $base = \OWA\Core\CoreAPI::serviceSingleton()->getModule( 'base' );
        $role = \OWA\Core\ServerRole::current();

        $facts = array(
            'OWA'     => (string) OWA_VERSION,
            \OWA\Core\CoreAPI::t( 'Server role' ) => sprintf( $role->runsScheduler()
                    ? \OWA\Core\CoreAPI::t( '%1$s (serves %2$s; runs the scheduler)' ) : \OWA\Core\CoreAPI::t( '%1$s (serves %2$s)' ),
                $role->name(), implode( ', ', $role->requestTypes() ) ),
            'PHP'     => PHP_VERSION . ' (' . PHP_SAPI . ')',
            \OWA\Core\CoreAPI::t( 'Schema' )  => sprintf( \OWA\Core\CoreAPI::t( '%d (this code requires %d)' ),
                (int) \OWA\Core\CoreAPI::getSetting( 'base', 'schema_version' ), (int) $base->required_schema_version ),
            \OWA\Core\CoreAPI::t( 'Tracker' ) => sprintf( \OWA\Core\CoreAPI::t( 'build %s (built here: %s)' ),
                self::shortBuild( (string) \OWA\Core\CoreAPI::getSetting( 'base', 'tracker_build' ) ),
                self::shortBuild( \OWA\Module\Base\Module::requiredTrackerBuild() ) ),
        );

        $findings = array();

        foreach ( EnvironmentCheck::all( (string) \OWA\Core\CoreAPI::getSetting( 'base', 'config_file' ) ) as $check ) {

            $findings[] = self::finding( $check['passed'] ? 'green' : 'red', (string) $check['name'],
                (string) $check['value'] . ( $check['passed'] ? '' : '. ' . $check['msg'] ) );
        }

        // Retired constants still in owa-config.php.
        foreach ( \OWA\Core\ServerRole::upgradeNotices() as $constant => $notice ) {

            $findings[] = self::finding( 'yellow', $constant, \OWA\Core\Strings::translate( $notice ) );
        }

        return self::section( \OWA\Core\CoreAPI::t( 'Installation' ), $findings, array( 'facts' => $facts ) );
    }
}

?>
