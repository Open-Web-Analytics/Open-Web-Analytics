<?php
namespace OWA\Module\Base\Classes;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * How long data is kept: the one place that answers it.
 *
 * TWO WINDOWS, in months, 0 meaning keep everything:
 *
 *   raw_retention_months    install only    owa_event_raw and every other
 *                                           day-partitioned event table
 *   cube_retention_months   install, then   that Property's cube
 *                           per Property
 *
 * NOT THE VISITOR STORE. A visitor's acquisition and last touch feed the
 * attribution lookback, which is a window of its own; deleting event history
 * says nothing about how long a returning visitor is recognised.
 *
 * A CUBE'S WINDOW IS CAPPED AT RAW'S. A cube is built from raw, so a window
 * longer than raw's names months there is nothing to build from; 0 means "the
 * same as raw". A cube can still hold months raw has lost to a manual prune
 * (partition-drop only=raw): a rebuild leaves those as built (CubeRebuildCli).
 *
 * ONLY SHORTENING RAW DESTROYS ANYTHING. A shorter cube window drops cube
 * partitions that raw can rebuild; a longer one is filled back by queueing a
 * rebuild of the months it now covers (backfills()); a longer raw window keeps
 * more from then on and brings nothing back.
 *
 * THE SETTINGS ARE THE ROUTINE POLICY. The scheduled partition-rotate reads
 * them and takes no keep=. partition-drop is the manual prune, for raw or a
 * cube on its own, and is not bound by them.
 */
class Retention {

    const RAW  = 'raw_retention_months';
    const CUBE = 'cube_retention_months';

    /** The scheduled job that applies the windows. */
    const ROTATE_JOB = 'rotate-partitions';

    /**
     * Milliseconds per event a cube rebuild takes, low and high.
     *
     * Measured on full rebuilds of two migrated installations: about 1.5
     * minutes per 100,000 events when raw fits the database's buffer pool, and
     * about 3 when it does not. An estimate shown as a range, not a promise.
     */
    const REBUILD_MS_PER_EVENT = array( 0.9, 1.8 );

    /** @return int raw's window in months; 0 keeps everything */
    public static function rawMonths() {

        return self::months( \OWA\Core\CoreAPI::getSetting( 'base', self::RAW ) );
    }

    /**
     * A Property's cube window in months: its own setting, else the install's,
     * capped at raw's; 0 keeps everything.
     *
     * @param string   $property_id
     * @param int|null $raw   raw's window, when the caller is asking "what if"
     * @param int|null $cube  this Property's cube setting, likewise
     * @return int
     */
    public static function cubeMonths( $property_id, $raw = null, $cube = null ) {

        $raw  = $raw === null ? self::rawMonths() : self::months( $raw );
        $cube = $cube === null
            ? self::months( \OWA\Core\CoreAPI::getSetting( 'base', self::CUBE, 'property', (string) $property_id ) )
            : self::months( $cube );

        return self::effectiveCube( $cube, $raw );
    }

    /**
     * The cube window a cube setting gives under a raw window.
     *
     * @param int $cube
     * @param int $raw
     * @return int
     */
    public static function effectiveCube( $cube, $raw ) {

        $cube = self::months( $cube );
        $raw  = self::months( $raw );

        if ( $cube <= 0 ) {

            return $raw;
        }

        return $raw > 0 ? min( $cube, $raw ) : $cube;
    }

    /**
     * The window for one partitioned table: its cube's, or raw's.
     *
     * @param string $table
     * @return int months
     */
    public static function monthsForTable( $table ) {

        $property_id = Cube\Cubes::propertyIdFor( $table );

        return $property_id !== '' ? self::cubeMonths( $property_id ) : self::rawMonths();
    }

    /**
     * The first day a window keeps, or null when it keeps everything.
     *
     * @param int $months
     * @return int|null yyyymmdd
     */
    public static function cutoff( $months ) {

        $months = self::months( $months );

        if ( $months <= 0 ) {

            return null;
        }

        $cutoff = \OWA\Module\Base\Controller\PartitionsCli::resolveCutoff( $months . 'months' );

        return $cutoff ? (int) $cutoff : null;
    }

    /**
     * What a window would drop from one table now.
     *
     * @param string $table
     * @param int    $months
     * @return array cutoff, effective (the boundary reached), partitions, rows (estimated)
     */
    public static function dropsFor( $table, $months ) {

        $out    = array( 'table' => $table, 'months' => self::months( $months ), 'cutoff' => self::cutoff( $months ),
                         'effective' => null, 'partitions' => array(), 'rows' => 0 );
        $db     = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $out['cutoff'] || ! $db->isPartitioned( $table ) ) {

            return $out;
        }

        $plan = $db->getDroppablePartitions( $table, (string) $out['cutoff'] );

        $out['partitions'] = (array) $plan['drop'];
        $out['effective']  = $plan['drop'] ? (int) $plan['effective'] : null;

        if ( $out['partitions'] ) {

            foreach ( $db->listPartitions( $table ) as $p ) {

                if ( in_array( $p['name'], $out['partitions'], true ) ) {

                    $out['rows'] += (int) $p['rows'];
                }
            }
        }

        return $out;
    }

    /**
     * The cubes whose window now reaches further back than they hold, and the
     * rebuild each needs.
     *
     * A cube is built back to the start of its window, or to the Property's
     * first day in raw when that is later. One that starts later than that is
     * owed the months between, which raw still has.
     *
     * @param array $cube_months property id => months, to ask "what if"; others read their settings
     * @param int|null $raw     raw's window, to ask "what if"
     * @return array[] property_id, table, from, to (yyyymmdd), events, minutes (low, high)
     */
    public static function backfills( array $cube_months = array(), $raw = null ) {

        $out = array();

        foreach ( Cube\Cubes::existing() as $property_id => $table ) {

            $months = self::cubeMonths( $property_id, $raw,
                array_key_exists( $property_id, $cube_months ) ? $cube_months[ $property_id ] : null );

            $need = self::backfillFor( (string) $property_id, $table, $months );

            if ( $need ) {

                $out[] = $need;
            }
        }

        return $out;
    }

    /**
     * One cube's owed rebuild under a window, or null.
     *
     * @param string $property_id
     * @param string $table
     * @param int    $months
     * @return array|null
     */
    public static function backfillFor( $property_id, $table, $months ) {

        $first_raw = Cube\Cubes::earliestDay( $property_id );

        if ( ! $first_raw ) {

            return null;
        }

        $start = self::cutoff( $months );
        $from  = $start ? max( (int) $start, (int) $first_raw ) : (int) $first_raw;
        $held  = self::firstDayIn( $table, Cube\Cubes::siteIds( $property_id ) );

        // An empty cube is the first build's to fill, which reaches back on its own.
        if ( ! $held || $from >= $held ) {

            return null;
        }

        $to     = (int) ( new \DateTimeImmutable( (string) $held ) )->modify( '-1 day' )->format( 'Ymd' );
        $events = self::countRaw( Cube\Cubes::siteIds( $property_id ), $from, $to );

        if ( ! $events ) {

            return null;
        }

        return array(
            'property_id' => (string) $property_id,
            'table'       => $table,
            'from'        => $from,
            'to'          => $to,
            'held_from'   => (int) $held,
            'events'      => $events,
            'minutes'     => self::rebuildMinutes( $events ),
        );
    }

    /**
     * Queue the rebuild each cube is owed. Deduplicated per Property, so saving
     * twice or a rotate after a save queues it once.
     *
     * @param array[] $backfills from backfills()
     * @return int jobs queued
     */
    public static function enqueueBackfills( array $backfills ) {

        $queued = 0;

        foreach ( $backfills as $b ) {

            $ok = \OWA\Core\CoreAPI::enqueueJob( 'cube-rebuild',
                array( 'property' => $b['property_id'], 'from' => (string) $b['from'], 'to' => (string) $b['to'] ),
                'cube-backfill:' . $b['property_id'] );

            if ( $ok !== false ) {

                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Rough rebuild time for this many events.
     *
     * @param int $events
     * @return int[] [ low, high ] minutes, at least 1
     */
    public static function rebuildMinutes( $events ) {

        list( $low, $high ) = self::REBUILD_MS_PER_EVENT;

        return array(
            max( 1, (int) round( $events * $low / 60000 ) ),
            max( 1, (int) round( $events * $high / 60000 ) ),
        );
    }

    /**
     * When the next scheduled rotate runs, or null if it is not scheduled.
     *
     * @return int|null unix time
     */
    public static function nextRotate() {

        $state = JobStatus::forJob( self::ROTATE_JOB );

        return ! empty( $state['next'] ) ? (int) $state['next'] : null;
    }

    /**
     * The confirmation a proposed change needs before it is saved, or none.
     *
     * Worded here rather than in the browser so the server, which knows what
     * is stored, says what will happen -- and so it is tested. Each change that
     * matters contributes a section; the title and the button come from the
     * most serious of them.
     *
     * @param array $proposed raw => months (install screen), cube => months, property_id (Property screen),
     *                        or cube_default => months (install screen)
     * @return array needed, tone ('danger' or 'notice'), title, paragraphs, proceed
     */
    public static function preview( array $proposed ) {

        $raw_now = self::rawMonths();
        $raw_new = array_key_exists( 'raw', $proposed ) ? self::months( $proposed['raw'] ) : $raw_now;

        $sections = array();

        // RAW, shorter: the one change that destroys data.
        if ( self::shorter( $raw_new, $raw_now ) ) {

            $drop = self::dropsFor( self::rawTable(), $raw_new );
            $sections[] = array( 'tone' => 'danger', 'title' => sprintf( 'Delete event data older than %d months?', $raw_new ),
                'proceed' => 'Save and delete', 'paragraphs' => self::rawParagraphs( $raw_new, $drop ) );
        }

        // CUBES: which Properties change, and how.
        $cube_now = array();
        $cube_new = array();

        foreach ( Cube\Cubes::existing() as $property_id => $table ) {

            $pid = (string) $property_id;

            if ( isset( $proposed['property_id'] ) && (string) $proposed['property_id'] !== $pid ) {

                continue;
            }

            $setting = null;

            if ( isset( $proposed['property_id'] ) && array_key_exists( 'cube', $proposed ) ) {

                $setting = $proposed['cube'];

            } elseif ( array_key_exists( 'cube_default', $proposed )
                       && \OWA\Core\CoreAPI::getSetting( 'base', self::CUBE, 'property', $pid, false ) === null ) {

                $setting = $proposed['cube_default'];
            }

            $cube_now[ $pid ] = self::cubeMonths( $pid );
            $cube_new[ $pid ] = self::cubeMonths( $pid, $raw_new, $setting );
        }

        $shorter = array();
        $longer  = array();

        foreach ( $cube_new as $pid => $months ) {

            if ( self::shorter( $months, $cube_now[ $pid ] ) ) {

                $shorter[ $pid ] = $months;

            } elseif ( self::shorter( $cube_now[ $pid ], $months ) ) {

                $longer[ $pid ] = $months;
            }
        }

        // A cube shortened only because raw was is covered by raw's section.
        if ( $shorter && ! self::shorter( $raw_new, $raw_now ) ) {

            $sections[] = array( 'tone' => 'notice', 'title' => 'Shorten reporting data?', 'proceed' => 'Save',
                'paragraphs' => self::cubeShorterParagraphs( $shorter ) );
        }

        if ( $longer ) {

            $owed = array();

            foreach ( $longer as $pid => $months ) {

                $need = self::backfillFor( $pid, Cube\Cubes::tableFor( $pid ), $months );

                if ( $need ) {

                    $owed[] = $need;
                }
            }

            if ( $owed ) {

                $sections[] = array( 'tone' => 'notice', 'title' => 'Rebuild older reporting data?',
                    'proceed' => 'Save and rebuild', 'paragraphs' => self::rebuildParagraphs( $owed ) );
            }
        }

        if ( ! $sections ) {

            return array( 'needed' => false );
        }

        $lead = $sections[0];

        foreach ( $sections as $s ) {

            if ( $s['tone'] === 'danger' ) {

                $lead = $s;
                break;
            }
        }

        $paragraphs = array();

        foreach ( $sections as $s ) {

            $paragraphs = array_merge( $paragraphs, $s['paragraphs'] );
        }

        return array(
            'needed'     => true,
            'tone'       => $lead['tone'],
            'title'      => $lead['title'],
            'paragraphs' => $paragraphs,
            'proceed'    => $lead['proceed'],
        );
    }

    /** @return string[] */
    private static function rawParagraphs( $months, array $drop ) {

        $when = self::when();

        $out = array();

        if ( $drop['partitions'] ) {

            $out[] = sprintf( 'At %s, event data before %s is deleted: about %s events.',
                $when, self::day( $drop['effective'] ), number_format( $drop['rows'] ) );

        } else {

            $out[] = sprintf( 'Nothing is old enough to delete yet. From %s on, event data older than %d months is '
                . 'deleted as it ages.', $when, $months );
        }

        $out[] = 'Deleted event data cannot be recovered. Reports for every Property are limited to the same window.';

        $out[] = 'Nothing is deleted before that run: changing this setting back in the meantime keeps everything. '
               . 'To delete sooner, run cmd=partition-drop.';

        return $out;
    }

    /** @return string[] */
    private static function cubeShorterParagraphs( array $shorter ) {

        $months = array_unique( array_values( $shorter ) );
        $which  = count( $shorter ) === 1 ? 'this Property' : sprintf( '%d Properties', count( $shorter ) );

        return array(
            sprintf( 'At %s, reports for %s stop showing data older than %s.',
                self::when(), $which,
                count( $months ) === 1 ? sprintf( '%d months', reset( $months ) ) : 'their new window' ),
            'The event data is kept. Lengthening this again rebuilds those months, as far back as event data is kept.',
        );
    }

    /** @return string[] */
    private static function rebuildParagraphs( array $owed ) {

        $events = 0;
        $low    = 0;
        $high   = 0;
        $from   = null;

        foreach ( $owed as $o ) {

            $events += $o['events'];
            $low    += $o['minutes'][0];
            $high   += $o['minutes'][1];
            $from    = $from === null ? $o['from'] : min( $from, $o['from'] );
        }

        $which = count( $owed ) === 1 ? 'this Property' : sprintf( '%d Properties', count( $owed ) );

        return array(
            sprintf( 'Reports for %s are rebuilt back to %s from the event data: about %s events.',
                $which, self::day( $from ), number_format( $events ) ),
            sprintf( 'Estimated rebuild time: %s. It runs in the background, starting within a few minutes of '
                . 'saving when the scheduler is running. Until it finishes, reports show the months they hold now.',
                $low === $high ? sprintf( 'about %d minute%s', $low, $low === 1 ? '' : 's' )
                               : sprintf( 'roughly %d to %d minutes', $low, $high ) ),
        );
    }

    /** "the next daily maintenance run (Tue 6 Oct, 04:12)", for "At ..." or "From ... on". */
    private static function when() {

        $next = self::nextRotate();

        return $next
            ? sprintf( 'the next daily maintenance run (%s)', date( 'D j M, H:i', $next ) )
            : 'the next maintenance run, once the scheduler is running';
    }

    /** 20240101 as "1 Jan 2024". */
    private static function day( $yyyymmdd ) {

        $d = \DateTimeImmutable::createFromFormat( 'Ymd', (string) $yyyymmdd );

        return $d ? $d->format( 'j M Y' ) : (string) $yyyymmdd;
    }

    /** @return string owa_event_raw, prefixed */
    public static function rawTable() {

        return \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' )->getTableName();
    }

    /** A window that keeps less than another. 0 keeps everything. */
    public static function shorter( $a, $b ) {

        $a = self::months( $a );
        $b = self::months( $b );

        return $a > 0 && ( $b <= 0 || $a < $b );
    }

    /** A stored or posted value as whole months, 0 for none or nonsense. */
    public static function months( $value ) {

        return is_numeric( $value ) && (int) $value > 0 ? (int) $value : 0;
    }

    /**
     * The first day a table holds for these sites, or null.
     *
     * @param string   $table
     * @param string[] $sites
     * @return int|null
     */
    private static function firstDayIn( $table, array $sites ) {

        if ( ! $sites ) {

            return null;
        }

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $row = (array) $db->get_row( sprintf( 'SELECT MIN(yyyymmdd) AS d FROM %s WHERE site_id IN (%s)',
            $table, implode( ',', array_fill( 0, count( $sites ), '?' ) ) ), array_values( $sites ) );

        return ! empty( $row['d'] ) ? (int) $row['d'] : null;
    }

    /**
     * Raw events for these sites between two days, inclusive.
     *
     * @return int
     */
    private static function countRaw( array $sites, $from, $to ) {

        if ( ! $sites ) {

            return 0;
        }

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $row = (array) $db->get_row( sprintf(
            'SELECT COUNT(*) AS n FROM %s WHERE site_id IN (%s) AND yyyymmdd BETWEEN %d AND %d',
            self::rawTable(), implode( ',', array_fill( 0, count( $sites ), '?' ) ), (int) $from, (int) $to ),
            array_values( $sites ) );

        return (int) ( $row['n'] ?? 0 );
    }
}
