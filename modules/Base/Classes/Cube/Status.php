<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The health of each Property's reporting cube, as the Reporting Cubes screen
 * shows it.
 *
 * NOTHING HERE IS STORED. Every answer is read from what already exists: the
 * cube's partitions and their built_at, raw, the rebuild-cube job's row, and
 * the custom dimension registry. So the screen cannot disagree with what a
 * build will do -- "behind from" is Builder::catchUpFrom(), the same walk the
 * next scheduled build takes, and the scheduler verdict is JobStatus, the same
 * one cmd=schedule-status prints.
 *
 * Each cube gets a list of checks, each GREEN, YELLOW or RED with one line
 * saying why, and a level that is the worst of them:
 *
 *   red     reports are wrong or will stop updating unless someone acts
 *   yellow  something is off that the next scheduled build, or time, resolves
 *   green   nothing to do
 */
class Status {

    const GREEN  = 'green';
    const YELLOW = 'yellow';
    const RED    = 'red';

    /** The job whose runs build every cube. */
    const JOB = 'rebuild-cube';

    /** How many recent days the detail screen tabulates. */
    const DAYS = 14;

    /** Lead left before rows reach the catch-all: red under the first, yellow under the second. */
    const LEAD_RED_DAYS    = 7;
    const LEAD_YELLOW_DAYS = 30;

    /**
     * Every cube, plus every Property that has collected but has no cube yet,
     * worst first.
     *
     * @return array[] one summary() per Property
     */
    public static function all() {

        $job = \OWA\Module\Base\Classes\JobStatus::forJob( self::JOB );
        $ids = array_unique( array_merge( array_keys( Cubes::existing() ), Cubes::awaitingFirstBuild() ) );
        $out = array();

        foreach ( $ids as $property_id ) {

            $out[] = self::summary( (string) $property_id, $job );
        }

        $rank = array( self::RED => 0, self::YELLOW => 1, self::GREEN => 2 );

        usort( $out, function ( $a, $b ) use ( $rank ) {

            return array( $rank[ $a['level'] ], $a['name'] ) <=> array( $rank[ $b['level'] ], $b['name'] );
        } );

        return $out;
    }

    /**
     * One Property's cube: its checks and the worst of them.
     *
     * @param string     $property_id
     * @param array|null $job  JobStatus::forJob( self::JOB ), passed in by all() so it is read once
     * @return array ['property_id','name','table','exists','level','checks','behind_from','scheduler']
     */
    public static function summary( $property_id, $job = null ) {

        $job   = $job ?? \OWA\Module\Base\Classes\JobStatus::forJob( self::JOB );
        $table = Cubes::tableFor( $property_id );
        $db    = \OWA\Core\CoreAPI::dbSingleton();

        $out = array(
            'property_id' => (string) $property_id,
            'name'        => self::propertyName( $property_id ),
            'table'       => $table,
            'exists'      => $table !== '' && $db->tableExists( $table ),
            'checks'      => array(),
            'behind_from' => null,
            'scheduler'   => $job,
        );

        $scheduler = self::schedulerCheck( $job );

        $out['checks'][] = $scheduler;

        if ( ! $out['exists'] ) {

            $earliest = Cubes::earliestDay( $property_id );

            $out['checks'][] = self::check( 'cube', 'Cube',
                $scheduler['level'] === self::RED ? self::RED : self::YELLOW,
                $earliest
                    ? sprintf( 'Not created yet. Raw holds data from %s; the next scheduled build creates '
                             . 'the cube and builds from that day.', self::day( $earliest ) )
                    : 'Not created yet, and nothing has been collected.' );

            $out['level'] = self::worst( $out['checks'] );

            return $out;
        }

        $last = self::lastBuildCheck( $job, $table );

        $out['checks'][] = $last;

        $builder = new Builder( $property_id );

        $out['behind_from'] = $builder->catchUpFrom( (int) date( 'Ymd', strtotime( '-1 day' ) ) );

        $out['checks'][] = self::builtCheck( $out['behind_from'],
            $scheduler['level'] === self::RED || $last['level'] === self::RED );

        $out['checks'][] = self::partitionsCheck( $table );
        $out['checks'][] = self::dimensionsCheck( $property_id );

        $out['level'] = self::worst( $out['checks'] );

        return $out;
    }

    /**
     * summary(), plus what the detail screen tabulates.
     *
     * @param string $property_id
     * @return array summary() + ['days','partitions','dimensions']
     */
    public static function detail( $property_id ) {

        $out = self::summary( $property_id );

        $out['days']       = array();
        $out['partitions'] = array();
        $out['dimensions'] = Dimensions::forProperty( $property_id );

        if ( ! $out['exists'] ) {

            return $out;
        }

        $out['partitions'] = self::partitionFacts( $out['table'] );
        $out['days']       = self::recentDays( $property_id, $out['table'] );

        return $out;
    }

    /**
     * Per recent day: raw rows for the Property, cube rows, the partition that
     * holds the day and when it was last built. A gap is raw above zero with
     * the cube at zero, or the two disagreeing on a settled day.
     *
     * @return array[] newest first
     */
    public static function recentDays( $property_id, $table ) {

        $to   = (int) date( 'Ymd' );
        $from = (int) date( 'Ymd', strtotime( '-' . ( self::DAYS - 1 ) . ' days' ) );

        $raw  = self::countsByDay(
            \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName(),
            $from, $to, Cubes::siteIds( $property_id ) );
        $cube = self::countsByDay( $table, $from, $to, null );

        $builder = new Builder( $property_id );
        $spans   = \OWA\Core\CoreAPI::dbSingleton()->getPartitionSpans( $table );
        $built   = array();
        $rows    = array();

        for ( $i = 0; $i < self::DAYS; $i++ ) {

            $day  = (int) date( 'Ymd', strtotime( "-$i days" ) );
            $span = self::spanFor( $spans, $day );

            if ( $span && ! array_key_exists( $span['name'], $built ) ) {

                $built[ $span['name'] ] = $builder->builtAt( $span );
            }

            $built_at = $span ? $built[ $span['name'] ] : null;

            $rows[] = array(
                'day'       => $day,
                'raw'       => (int) ( $raw[ $day ] ?? 0 ),
                'cube'      => (int) ( $cube[ $day ] ?? 0 ),
                'partition' => $span ? $span['name'] : '',
                'built_at'  => $built_at,
                'settled'   => $span ? $builder->isSettled( $span, $built_at ) : false,
            );
        }

        return $rows;
    }

    /**
     * Whether a Profile's reports can be drawn, and if not, what to tell the
     * reader. Null when its Property's cube exists.
     *
     * This answers "is reporting ready?", not "is the tag working?" -- that is
     * the Tracking Tag screen's question (SitesInvocation::lastEventReceived()).
     * So it never reads raw: a cube is created only by a scheduled build, and
     * what stands between a Property and its first cube is either the scheduler
     * or the next build.
     *
     * @param string $site_id
     * @return array|null ['state','headline','message','cron','property_id']
     */
    public static function readiness( $site_id ) {

        $property_id = Cubes::propertyIdForSite( (string) $site_id );

        if ( $property_id !== '' && Cubes::exists( $property_id ) ) {

            return null;
        }

        $health = \OWA\Module\Base\Classes\SchedulerHealth::problem();
        $next   = null;

        if ( $property_id !== '' && ! $health ) {

            $next = \OWA\Module\Base\Classes\JobStatus::forJob( self::JOB )['next'];
        }

        return self::readinessFor( $property_id, (bool) $health, $next );
    }

    /**
     * The message for each way reporting can be not ready. Pure, so each can be
     * asserted without an installation in that state.
     *
     * @param string   $property_id  '' when the Profile has no Property
     * @param bool     $scheduler_down
     * @param int|null $next         when the next scheduled build is due
     * @return array
     */
    public static function readinessFor( $property_id, $scheduler_down, $next ) {

        if ( $property_id === '' ) {

            return array(
                'state'       => 'no_property',
                'headline'    => 'This Profile belongs to no Property.',
                'message'     => 'Reports are built per Property, so there is nothing to report on '
                               . 'until this Profile is given one.',
                'cron'        => '',
                'property_id' => '',
            );
        }

        if ( $scheduler_down ) {

            return array(
                'state'       => 'scheduler',
                'headline'    => 'Reports need the job scheduler.',
                'message'     => "Reporting data is built by OWA's scheduled jobs, and they are not "
                               . 'running. Add this line to the crontab of the user that owns your '
                               . 'OWA files; reports appear after its first build.',
                'cron'        => \OWA\Module\Base\Classes\SchedulerHealth::cronLine(),
                'property_id' => (string) $property_id,
            );
        }

        return array(
            'state'       => 'waiting',
            'headline'    => 'No reporting data yet.',
            'message'     => 'Reports appear after the first scheduled build once data has arrived'
                           . ( $next ? ' -- the next is due ' . \OWA\Module\Base\Classes\JobStatus::readable( $next ) : '' )
                           . '. The Tracking Tag page shows whether this Profile has received any.',
            'cron'        => '',
            'property_id' => (string) $property_id,
        );
    }

    /**
     * How far the cube's partitions reach, and what is in the catch-all.
     *
     * @param string $table
     * @return array ['count','first','daily_through','lead_end','today_daily','catch_all','catch_all_rows']
     */
    public static function partitionFacts( $table ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $spans = $db->getPartitionSpans( $table );
        $today = (int) date( 'Ymd' );

        $out = array(
            'count'          => count( $spans ),
            'first'          => $spans ? (int) $spans[0]['start'] : null,
            'lead_end'       => $spans ? (int) end( $spans )['less_than'] : null,
            'daily_through'  => null,
            'today_daily'    => false,
            'catch_all'      => '',
            'catch_all_rows' => 0,
        );

        foreach ( $spans as $span ) {

            if ( self::isOneDay( $span ) && (int) $span['less_than'] > $today ) {

                $out['daily_through'] = (int) date( 'Ymd', strtotime( $span['less_than'] . ' -1 day' ) );

                if ( (int) $span['start'] === $today ) {

                    $out['today_daily'] = true;
                }
            }
        }

        foreach ( $db->listPartitions( $table ) as $p ) {

            if ( strtoupper( (string) $p['less_than'] ) === OWA_DTD_PARTITION_MAXVALUE ) {

                $out['catch_all'] = $p['name'];

                $row = $db->get_row( sprintf( 'SELECT COUNT(*) AS n FROM %s PARTITION (%s)',
                    $table, $p['name'] ) );

                $out['catch_all_rows'] = (int) ( $row['n'] ?? 0 );
            }
        }

        return $out;
    }

    /** Whether the scheduled build is running on time, as cmd=schedule-status judges it. */
    protected static function schedulerCheck( array $job ) {

        $health = \OWA\Module\Base\Classes\SchedulerHealth::problem();

        if ( $health ) {

            return self::check( 'scheduler', 'Scheduled build', self::RED,
                $health['headline'] . ' Cubes are built only by the scheduler, so reports will not '
              . 'update until it runs. Add this cron entry: '
              . \OWA\Module\Base\Classes\SchedulerHealth::cronLine() );
        }

        if ( $job['running'] ) {

            return self::check( 'scheduler', 'Scheduled build', self::GREEN, 'Running now.' );
        }

        if ( $job['reason'] !== null ) {

            return self::check( 'scheduler', 'Scheduled build', self::RED, $job['reason'] );
        }

        return self::check( 'scheduler', 'Scheduled build', self::GREEN, $job['next']
            ? 'On schedule. Next run ' . \OWA\Module\Base\Classes\JobStatus::readable( $job['next'] ) . '.'
            : 'On schedule.' );
    }

    /**
     * Whether the last scheduled run stopped on THIS cube.
     *
     * The run's message is where the builder records it -- "<table> stopped at
     * <partition>: <reason>", first failure first -- and the scheduler keeps it
     * in last_message.
     */
    protected static function lastBuildCheck( array $job, $table ) {

        $row = $job['row'];

        if ( ! $row || empty( $row['last_run_at'] ) ) {

            return self::check( 'last_build', 'Last build', self::YELLOW,
                'The scheduled build has never run.' );
        }

        $when    = \OWA\Module\Base\Classes\JobStatus::readable( $row['last_run_at'] );
        $message = (string) ( $row['last_message'] ?? '' );

        if ( ( $row['last_status'] ?? '' ) === 'failed' && strpos( $message, $table . ' stopped at' ) !== false ) {

            $mine = substr( $message, strpos( $message, $table . ' stopped at' ) );
            $mine = strtok( $mine, ';' );

            return self::check( 'last_build', 'Last build', self::RED, sprintf(
                'Stopped at %s: %s. The next scheduled build resumes there; it is retried at every run.',
                $when, rtrim( substr( $mine, strlen( $table . ' stopped at ' ) ), '. ' ) ) );
        }

        return self::check( 'last_build', 'Last build', self::GREEN, sprintf( '%s, %s.',
            $when, ( $row['last_status'] ?? '' ) === 'failed' ? 'failed on another cube' : 'ok' ) );
    }

    /** Whether everything before yesterday is settled. */
    protected static function builtCheck( $behind_from, $stuck ) {

        if ( $behind_from === null ) {

            return self::check( 'built', 'Built through', self::GREEN,
                'Up to date: every day before yesterday is settled.' );
        }

        return self::check( 'built', 'Built through', $stuck ? self::RED : self::YELLOW, sprintf(
            'Behind from %s. %s', self::day( $behind_from ), $stuck
                ? 'The scheduled build is not reaching it -- see above.'
                : 'The next scheduled build rebuilds from there.' ) );
    }

    /** Rows in the catch-all, how far the lead reaches, and whether today is a daily partition. */
    protected static function partitionsCheck( $table ) {

        $facts = self::partitionFacts( $table );

        if ( $facts['catch_all_rows'] > 0 ) {

            return self::check( 'partitions', 'Partitions', self::RED, sprintf(
                '%s rows are in the catch-all partition %s, which no build can rebuild. The dated '
              . 'partitions have run out: run cmd=partition-rotate.',
                number_format( $facts['catch_all_rows'] ), $facts['catch_all'] ) );
        }

        $lead_days = $facts['lead_end']
            ? (int) floor( ( strtotime( (string) $facts['lead_end'] ) - strtotime( date( 'Ymd' ) ) ) / 86400 )
            : 0;

        if ( $lead_days < self::LEAD_RED_DAYS ) {

            return self::check( 'partitions', 'Partitions', self::RED, sprintf(
                'Dated partitions end %s, %d day(s) away; after that rows land in the catch-all. '
              . 'Run cmd=partition-rotate, which the rotate-partitions job does daily.',
                $facts['lead_end'] ? self::day( $facts['lead_end'] ) : 'now', $lead_days ) );
        }

        if ( $lead_days < self::LEAD_YELLOW_DAYS ) {

            return self::check( 'partitions', 'Partitions', self::YELLOW, sprintf(
                'Dated partitions end %s, %d days away. The rotate-partitions job normally keeps a year '
              . 'ahead; check that it is running.', self::day( $facts['lead_end'] ), $lead_days ) );
        }

        if ( ! $facts['today_daily'] ) {

            return self::check( 'partitions', 'Partitions', self::YELLOW,
                'Today is not in a daily partition, so every build rewrites a whole period. '
              . 'cmd=partition-rotate carves the daily front back.' );
        }

        return self::check( 'partitions', 'Partitions', self::GREEN, sprintf(
            '%d partitions, from %s; daily through %s; dated through %s; catch-all empty.',
            $facts['count'], self::day( $facts['first'] ), self::day( $facts['daily_through'] ),
            self::day( (int) date( 'Ymd', strtotime( $facts['lead_end'] . ' -1 day' ) ) ) ) );
    }

    /** Custom dimensions whose column is not on the cube yet, or could not be added. */
    protected static function dimensionsCheck( $property_id ) {

        $failed  = 0;
        $pending = 0;
        $all     = Dimensions::forProperty( $property_id );

        foreach ( $all as $dimension ) {

            if ( $dimension['state'] === 'failed' ) {

                $failed++;

            } elseif ( $dimension['state'] !== 'applied' ) {

                $pending++;
            }
        }

        if ( $failed ) {

            return self::check( 'dimensions', 'Custom dimensions', self::YELLOW, sprintf(
                '%d could not be added to the cube; the Custom Dimensions screen says why.', $failed ) );
        }

        if ( $pending ) {

            return self::check( 'dimensions', 'Custom dimensions', self::YELLOW, sprintf(
                '%d waiting for their column, added at the next build or within fifteen minutes.', $pending ) );
        }

        return self::check( 'dimensions', 'Custom dimensions', self::GREEN,
            $all ? sprintf( '%d registered, all reportable.', count( $all ) ) : 'None registered.' );
    }

    /**
     * Row counts per day, for a table with (site_id, yyyymmdd) indexed.
     *
     * @param string        $table
     * @param int           $from
     * @param int           $to
     * @param string[]|null $sites  null for every row (a cube holds one Property's)
     * @return array yyyymmdd => count
     */
    protected static function countsByDay( $table, $from, $to, $sites ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $where = sprintf( 'yyyymmdd >= %d AND yyyymmdd <= %d', (int) $from, (int) $to );

        if ( $sites !== null ) {

            if ( ! $sites ) {

                return array();
            }

            $quoted = array();

            foreach ( $sites as $site_id ) {

                $quoted[] = "'" . $db->prepare( $site_id ) . "'";
            }

            $where .= ' AND site_id IN (' . implode( ', ', $quoted ) . ')';
        }

        $out = array();

        foreach ( (array) $db->get_results( sprintf(
                'SELECT yyyymmdd, COUNT(*) AS n FROM %s WHERE %s GROUP BY yyyymmdd', $table, $where ) ) as $row ) {

            $out[ (int) $row['yyyymmdd'] ] = (int) $row['n'];
        }

        return $out;
    }

    protected static function spanFor( array $spans, $day ) {

        foreach ( $spans as $span ) {

            if ( (int) $span['start'] <= $day && $day < (int) $span['less_than'] ) {

                return $span;
            }
        }

        return null;
    }

    protected static function isOneDay( array $span ) {

        return date( 'Ymd', strtotime( $span['start'] . ' +1 day' ) ) === (string) $span['less_than'];
    }

    protected static function propertyName( $property_id ) {

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->load( $property_id );

        return (string) ( $property->get( 'name' ) ?: $property_id );
    }

    /** yyyymmdd as a person reads it. */
    public static function day( $yyyymmdd ) {

        $ts = strtotime( (string) $yyyymmdd );

        return $ts ? date( 'j M Y', $ts ) : (string) $yyyymmdd;
    }

    protected static function check( $key, $label, $level, $detail ) {

        return array( 'key' => $key, 'label' => $label, 'level' => $level, 'detail' => $detail );
    }

    /** The worst level among the checks. */
    public static function worst( array $checks ) {

        $level = self::GREEN;

        foreach ( $checks as $check ) {

            if ( $check['level'] === self::RED ) {

                return self::RED;
            }

            if ( $check['level'] === self::YELLOW ) {

                $level = self::YELLOW;
            }
        }

        return $level;
    }
}

?>
