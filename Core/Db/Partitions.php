<?php
namespace OWA\Core\Db;

/**
 * Partition management for the tables OWA partitions by date: the ranges a
 * table is cut into, and the statements that add, merge, split, exchange and
 * drop its partitions.
 *
 * Works on one Db and reaches the database only through it. Every call to a
 * Db method, including the public methods of this class, goes back through
 * the Db, so a driver that overrides one (listPartitions(),
 * supportsPartitioning(), reorganizePartitions(), ...) is honoured wherever
 * this class uses it. Db keeps a delegating method for each public method and
 * constant here, and OWA calls those.
 *
 * @phpstan-import-type PartitionRanges from \OWA\Core\Db
 * @phpstan-import-type Span from \OWA\Core\Db
 * @phpstan-import-type Reshape from \OWA\Core\Db
 */
class Partitions {

    /** @var \OWA\Core\Db */
    private $db;

    /**
     * @param \OWA\Core\Db $db
     */
    public function __construct( \OWA\Core\Db $db ) {

        $this->db = $db;
    }

    /**
     * Run a partition change through Db::changePartitions(), which is
     * protected and which a driver may override.
     *
     * @param string $table_name
     * @param string $sql
     * @return mixed  query()'s result
     */
    private function changePartitions( $table_name, $sql ) {

        return ( function ( $table_name, $sql ) {

            return $this->changePartitions( $table_name, $sql );
        } )->call( $this->db, $table_name, $sql );
    }

    /**
     * Is this table partitioned?
     *
     * @param string $table_name
     * @return bool
     */
    public function isPartitioned( $table_name ) {

        return (bool) $this->db->listPartitions( $table_name );
    }

    /**
     * Partitions per table beyond which an operation asks to be confirmed.
     *
     * Each partition is a file, and they are shared with every other table
     * through innodb_open_files, which is typically 4,000. Past that limit
     * MySQL closes and reopens tablespaces under load, which degrades
     * everything on the instance, and metadata operations slow in proportion to
     * the count. Quarter-month over a decade is 480 per table, so this is
     * reachable without trying.
     *
     * It is a prompt, not a ceiling: force=1 is there for someone who has done
     * the arithmetic.
     */
    const PARTITION_COUNT_LIMIT = 400;

    /**
     * How many whole future months of partitions to keep ahead of today.
     *
     * The catch-all exists so that a write past the last boundary is accepted
     * rather than rejected, but anything landing in it cannot be dropped by a
     * retention cutoff -- it has no upper bound, so it is never wholly older
     * than any date. Keeping a year of partitions ahead means the catch-all
     * stays empty in normal operation: every write finds a real bounded
     * partition, retention reaches the date asked for, and the periodic top-up
     * splits an empty catch-all, which is cheap. It also means a top-up that
     * stops running has a year of slack before anything is affected.
     */
    const PARTITION_MONTHS_AHEAD = 12;

    /**
     * How much of the cube's lead is daily: two months' worth of partitions.
     *
     * THE LEAD IS ALWAYS TWELVE MONTHS, whatever the granularity. An ordinary
     * fact table holds one granularity across the whole of it. The cube holds a
     * mix: about two months daily at the front, the rest at the granularity the
     * table is otherwise on -- so the lead is 2 + 10, not twelve months plus a
     * separate daily thing bolted on the front: there is ONE lead, and its
     * granularity varies along it.
     *
     * Measured as MONTHS OF COVERAGE, not as a count of partitions. The daily
     * part extends backwards as well as forwards -- elapsed days stay daily until
     * their month merges -- so it is the span from its first daily partition to
     * its last that has to be two months, not the distance ahead of today. A
     * count would do the same job on average and get February wrong: after a
     * 31-day month merges, 31 + 28 is 59, and a flat gate of 60 lets a third
     * month through.
     *
     * Fixed rather than a setting: it is how much of the lead is daily, and an install
     * that lowered it below the window would silently stop late events being
     * folded in.
     */
    const CUBE_DAILY_MONTHS = 2;

    /**
     * Days of slack on the lower bound used to prune per-session queries.
     *
     * See factDateRange(). Sized empirically, not from a known mechanism.
     */
    const FACT_LOWER_BOUND_SLACK_DAYS = 30;

    /**
     * Day-of-month cut points per granularity, coarsest first.
     *
     * Named for how many parts a month is divided into, never for a duration:
     * months vary from 28 to 31 days, so any name carrying a day count would be
     * wrong in some month. A third of January is 11 days and a third of
     * February is 8; both are still a third of their month.
     *
     * Cutting only on days of the month is also what keeps every boundary
     * aligned to a month start, which is what lets a granularity change rewrite
     * one month at a time instead of the whole table.
     *
     * `daily` is listed last and is the only entry whose cuts a month can fail
     * to carry -- cutsForMonth() clamps the 29th through 31st away in a short
     * month, which is why anything comparing a table's boundaries against this
     * list must compare against the clamped result and not against the constant.
     *
     * It is also the only one that changes the arithmetic of the lead. The lead
     * is a count of MONTHS filled at the table's own granularity, so
     * PARTITION_MONTHS_AHEAD of 12 is twelve partitions monthly and around 365
     * daily -- past PARTITION_COUNT_LIMIT on its own, before any history. Daily
     * is for a short lead over a short detail window (a rebuild horizon), not
     * for a table kept daily the way the other three are kept.
     */
    const PARTITION_CUTS = [
        'monthly'       => [ 1 ],
        'half-month'    => [ 1, 16 ],
        'quarter-month' => [ 1, 8, 15, 22 ],
        'daily'         => [
             1,  2,  3,  4,  5,  6,  7,  8,  9, 10,
            11, 12, 13, 14, 15, 16, 17, 18, 19, 20,
            21, 22, 23, 24, 25, 26, 27, 28, 29, 30,
            31,
        ],
    ];

    /**
     * The upper boundary a table needs for a given lead of whole future months.
     *
     * Counted from the start of the current month, so the result does not move
     * within a month: the current period plus the lead. Twelve months ahead of
     * mid-August 2026 is 1 September 2027 -- September 2026 through August 2027
     * being the twelve future months.
     *
     * @param int $months
     * @return string yyyymmdd
     */
    public static function partitionLeadBoundary( $months = self::PARTITION_MONTHS_AHEAD ) {

        return date( 'Ymd', strtotime( date( 'Ym' ) . '01 +' . ( (int) $months + 1 ) . ' months' ) );
    }

    /**
     * A twelve-month lead, with the first months daily where asked for.
     *
     * The shape Db::createTable() gives a new table and the shape
     * partition-rotate maintains, built in one place so a table is created in
     * the layout it will be kept in rather than one the first rotate has to
     * reshape.
     *
     * THE LAST SPAN IS ALWAYS THE COARSE ONE. Granularity is never stored:
     * inferPartitionGranularity() reads the last span, so a lead that ended
     * daily would have its lead extended a year AT DAILY by the next rotate --
     * some 365 partitions, silently.
     *
     * TWO HALVES FROM TWO PLACES, and only one of them is declared. How much is
     * daily is the entity's, fixed. The granularity of the rest is the
     * OPERATOR'S, and is not recorded anywhere but the shape of the table
     * itself -- inferPartitionGranularity() reads it back off the last span. So
     * the caller supplies it: at creation there is no table to infer from and
     * monthly is the only answer available; a caller maintaining an existing
     * table passes what that table is on.
     *
     * @param int    $daily_months  how many of the leading months are daily
     * @param string $granularity   the rest of the lead; the operator's choice
     * @return PartitionRanges
     */
    public static function makeLeadRanges( $daily_months = 0, $granularity = 'monthly' ) {

        if ( ! self::isPartitionGranularity( $granularity ) ) {

            $granularity = 'monthly';
        }

        $month    = date( 'Ym01' );
        $boundary = self::partitionLeadBoundary();

        if ( $daily_months < 1 ) {

            return self::makePartitionRanges(
                date( 'Ymd' ), date( 'Ymd', strtotime( $boundary . ' -1 day' ) ), $granularity );
        }

        $coarse_from = date( 'Ym01', strtotime( $month . ' +' . (int) $daily_months . ' month' ) );
        $ranges      = self::makePartitionRangesForSpan( $month, $coarse_from, 'daily' );

        return $ranges + self::makePartitionRangesForSpan( $coarse_from, $boundary, $granularity );
    }

    /**
     * Is this a granularity we can partition by?
     *
     * @param string $granularity
     * @return bool
     */
    public static function isPartitionGranularity( $granularity ) {

        return isset( self::PARTITION_CUTS[ $granularity ] );
    }

    /**
     * The day-of-month cut points for one month.
     *
     * @param \DateTimeImmutable $month  any day within the month
     * @param string $granularity
     * @return int[] ascending days of the month
     */
    private static function cutsForMonth( $month, $granularity ) {

        if ( ! isset( self::PARTITION_CUTS[ $granularity ] ) ) {

            return [];
        }

        $days = (int) $month->format( 't' );
        $cuts = [];

        foreach ( self::PARTITION_CUTS[ $granularity ] as $day ) {

            // A short month cannot be cut past its end.
            if ( $day <= $days ) {

                $cuts[] = $day;
            }
        }

        return $cuts;
    }

    /**
     * Partition boundaries covering a date range.
     *
     * Each partition is named for the first day it holds and bounded by the
     * first day it does not. Rows whose date is malformed -- a handful of
     * installs carry yyyymmdd values of 0 or 1 -- land in the first partition,
     * since RANGE has no lower bound.
     *
     * @param int|string $start_yyyymmdd
     * @param int|string $end_yyyymmdd
     * @param string $granularity
     * @return PartitionRanges in range order
     */
    public static function makePartitionRanges( $start_yyyymmdd, $end_yyyymmdd, $granularity = 'monthly' ) {

        return self::buildRanges( $start_yyyymmdd, $end_yyyymmdd, $granularity, false );
    }

    /**
     * Partition boundaries that exactly tile a half-open span.
     *
     * REORGANIZE requires the replacement partitions to cover precisely the
     * span of the ones they replace -- no gap, no overhang -- so boundaries
     * outside the span are clamped to it.
     *
     * @param int|string $start_yyyymmdd  first day the span holds
     * @param int|string $end_yyyymmdd    first day it does not
     * @param string $granularity
     * @return PartitionRanges
     */
    public static function makePartitionRangesForSpan( $start_yyyymmdd, $end_yyyymmdd, $granularity = 'monthly' ) {

        return self::buildRanges( $start_yyyymmdd, $end_yyyymmdd, $granularity, true );
    }

    /**
     * Walk the months in a range, emitting a partition per cut point.
     *
     * @param int|string $start_yyyymmdd
     * @param int|string $end_yyyymmdd
     * @param string $granularity
     * @param bool   $exact  treat the end as exclusive and clamp to it
     * @return PartitionRanges
     */
    private static function buildRanges( $start_yyyymmdd, $end_yyyymmdd, $granularity, $exact ) {

        if ( ! self::isPartitionGranularity( $granularity ) ) {

            return [];
        }

        $start = \DateTimeImmutable::createFromFormat( 'Ymd|', (string) $start_yyyymmdd );
        $end   = \DateTimeImmutable::createFromFormat( 'Ymd|', (string) $end_yyyymmdd );

        if ( ! $start || ! $end ) {

            return [];
        }

        if ( $exact ? ( $end <= $start ) : ( $end < $start ) ) {

            return [];
        }

        $ranges = [];
        $month  = $start->modify( 'first day of this month' )->setTime( 0, 0 );
        $limit  = $end->modify( 'first day of next month' )->setTime( 0, 0 );

        while ( $month < $limit ) {

            $next_month = $month->modify( 'first day of next month' );

            $cuts = self::cutsForMonth( $month, $granularity );

            foreach ( $cuts as $i => $day ) {

                $from = $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $day );

                $to = isset( $cuts[ $i + 1 ] )
                    ? $month->setDate( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $cuts[ $i + 1 ] )
                    : $next_month;

                if ( $exact ) {

                    // Nothing outside the span the replacement has to tile.
                    if ( $to <= $start || $from >= $end ) {

                        continue;
                    }

                    if ( $from < $start ) {

                        $from = $start;
                    }

                    if ( $to > $end ) {

                        $to = $end;
                    }
                }

                $ranges[ 'p' . $from->format( 'Ymd' ) ] = $to->format( 'Ymd' );
            }

            $month = $next_month;
        }

        return $ranges;
    }

    /**
     * Build the clause that partitions a table by range.
     *
     * @param string $column
     * @param PartitionRanges $ranges
     * @param bool   $with_maxvalue  append the catch-all
     * @return string  empty when the driver cannot partition
     */
    public function makePartitionClause( $column, $ranges, $with_maxvalue = true ) {

        if ( ! $this->db->supportsPartitioning() || ! $ranges ) {

            return '';
        }

        $parts = [];

        foreach ( $ranges as $name => $less_than ) {

            $parts[] = sprintf( OWA_DTD_PARTITION_LESS_THAN, $name, $less_than );
        }

        if ( $with_maxvalue ) {

            $parts[] = sprintf( OWA_DTD_PARTITION_LESS_THAN, 'pmax', OWA_DTD_PARTITION_MAXVALUE );
        }

        return sprintf( OWA_DTD_PARTITION_BY_RANGE, $column, implode( ', ', $parts ) );
    }

    /**
     * Drop the named partition.
     *
     * @param string $table_name
     * @param string $partition
     * @return bool
     */
    public function dropPartition( $table_name, $partition ) {

        if ( ! $this->db->supportsPartitioning()
          || ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name )
          || ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $partition ) ) {

            return false;
        }

        return $this->changePartitions( $table_name, sprintf( OWA_SQL_DROP_PARTITION, $table_name, $partition ) );
    }

    /**
     * Replace one or more contiguous partitions with a different set.
     *
     * Only the named partitions are rewritten, so changing granularity can be
     * done a period at a time rather than rebuilding the whole table.
     *
     * @param string $table_name
     * @param list<string> $from   partition names being replaced
     * @param PartitionRanges $ranges to replace them with
     * @return bool
     */
    public function reorganizePartitions( $table_name, $from, $ranges ) {

        if ( ! $this->db->supportsPartitioning() || ! $from || ! $ranges
          || ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name ) ) {

            return false;
        }

        foreach ( $from as $name ) {

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $name ) ) {

                return false;
            }
        }

        $parts = [];

        foreach ( $ranges as $name => $less_than ) {

            $parts[] = sprintf( OWA_DTD_PARTITION_LESS_THAN, $name, $less_than );
        }

        return $this->changePartitions( $table_name, sprintf(
            OWA_SQL_REORGANIZE_PARTITION, $table_name, implode( ',', $from ), implode( ', ', $parts )
        ) );
    }

    /**
     * Copy a partitioned table's structure WITHOUT its partitions.
     *
     * For a staging table that EXCHANGE PARTITION will swap in. The swap
     * refuses a partitioned table, so the partitions have to go -- and
     * CREATE TABLE LIKE then REMOVE PARTITIONING creates every one of them
     * only to delete it again: 2,690ms plus 1,491ms on a 72-partition cube,
     * against 55ms to create it flat in the first place.
     *
     * Taken from the LIVE table's own DDL rather than rebuilt from an entity,
     * because the swap compares column for column and the table may carry
     * columns no entity declares -- a registered custom dimension is added at
     * runtime. Reading what is actually there is the only way to match it.
     *
     * @param string $new     table to create
     * @param string $source  partitioned table to copy
     * @return bool
     */
    public function createUnpartitionedCopy( $new, $source ) {

        if ( ! defined( 'OWA_SQL_SHOW_CREATE_TABLE' )
          || ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $new )
          || ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $source ) ) {

            return false;
        }

        $row = $this->db->get_row( sprintf( OWA_SQL_SHOW_CREATE_TABLE, $source ) );
        $row = (array) $row;
        $ddl = isset( $row['Create Table'] ) ? $row['Create Table'] : '';

        if ( ! $ddl ) {

            return false;
        }

        /*
         * The partition clause is last, and MySQL emits it inside a version
         * comment. Cutting at whichever marker appears keeps the column list,
         * the keys and the table options exactly as they are -- which is what
         * the swap compares.
         */
        foreach ( [ '/*!50100 PARTITION BY', "\nPARTITION BY", ' PARTITION BY' ] as $marker ) {

            $at = strpos( $ddl, $marker );

            if ( $at !== false ) {

                $ddl = rtrim( substr( $ddl, 0, $at ) );

                break;
            }
        }

        $ddl = preg_replace(
            '/^CREATE TABLE `' . preg_quote( (string) $source, '/' ) . '`/',
            'CREATE TABLE `' . $new . '`',
            $ddl, 1, $renamed );

        // Refuse rather than create a second copy of the source table.
        if ( ! $renamed ) {

            return false;
        }

        return (bool) $this->db->query( $ddl );
    }

    /**
     * Swap a partition's contents with an unpartitioned table's, atomically.
     *
     * The two tablespaces change places: afterwards the staging table holds
     * what the partition held, so the old contents survive to drop or inspect.
     *
     * This is the commit. A build that dies before it leaves the live table as
     * it was, and the next run rebuilds.
     *
     * @param string $table_name  the partitioned table
     * @param string $partition   the partition to exchange
     * @param string $with_table  an unpartitioned table of identical structure
     * @return bool
     */
    public function exchangePartition( $table_name, $partition, $with_table ) {

        if ( ! $this->db->supportsPartitioning() || ! defined( 'OWA_SQL_EXCHANGE_PARTITION' ) ) {

            return false;
        }

        foreach ( [ $table_name, $partition, $with_table ] as $identifier ) {

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $identifier ) ) {

                return false;
            }
        }

        return (bool) $this->changePartitions( $table_name, sprintf(
            OWA_SQL_EXCHANGE_PARTITION, $table_name, $partition, $with_table ) );
    }

    /**
     * The partitions of a table as spans, excluding the catch-all.
     *
     * RANGE gives each partition an upper bound only, so a partition's lower
     * bound is the previous partition's upper bound. The first one has none --
     * it holds everything below its boundary -- and is reported with the start
     * encoded in its name, which is what this class's own naming provides.
     *
     * @param string $table_name
     * @return list<Span>
     */
    public function getPartitionSpans( $table_name ) {

        $spans = [];
        $prev  = null;

        foreach ( $this->db->listPartitions( $table_name ) as $p ) {

            if ( strtoupper( $p['less_than'] ) === OWA_DTD_PARTITION_MAXVALUE ) {

                continue;
            }

            $start = $prev;

            if ( $start === null ) {

                // No lower bound; recover the intended start from the name.
                $start = preg_match( '/^p(\d{8})$/', $p['name'], $m ) ? $m[1] : $p['less_than'];
            }

            $spans[] = [
                'name'      => $p['name'],
                'start'     => (string) $start,
                'less_than' => (string) $p['less_than'],
            ];

            $prev = $p['less_than'];
        }

        return $spans;
    }

    /**
     * The range of the partitioning column that a known session's fact rows can
     * occupy, for queries that select them.
     *
     * Partition pruning reads only the partitioning column, so a query
     * constrained on session_id alone has to visit every partition. A row
     * belonging to a session cannot be older than the session itself, so the
     * session's own date is a lower bound that can never exclude a valid row --
     * it only tells the optimizer which partitions cannot possibly hold one.
     *
     * The date must be the one stored on the session row, which the server
     * assigned. Not the current event's date, which is later for a session
     * running past midnight, and not a date taken from an id, which the tracker
     * mints from the browser's clock.
     *
     * The slack is empirical, and deliberately generous, because the ways this
     * invariant can be broken are not fully known.
     *
     * Both dates are server-assigned from the same clock, so neither queue lag
     * nor a client clock can move them apart -- yet rows dated before their
     * session do occur, one of them two days out with no established cause.
     * A month costs one extra partition on a monthly layout, which is cheap
     * against that. Narrow it only with evidence about the mechanism.
     *
     * Returns null where the date is unusable, in which case the caller simply
     * does not constrain -- slower, never wrong.
     *
     * @param mixed $session_yyyymmdd  a stored date, any type
     * @return array{start: string, end: string}|null yyyymmdd, or null where unusable
     */
    public static function factDateRange( $session_yyyymmdd ) {

        $value = (string) $session_yyyymmdd;

        // Installations carry rows with a yyyymmdd of 0, and anything that is
        // not a plausible date cannot bound anything.
        if ( ! preg_match( '/^\d{8}$/', $value ) || $value <= '19700101' ) {

            return null;
        }

        $date = \DateTimeImmutable::createFromFormat( 'Ymd|', $value );

        if ( ! $date ) {

            return null;
        }

        // The upper bound is today, not the session's date: rows are added to a
        // session as it runs, and one replayed or backfilled later still lands
        // on the day it was processed. Nothing can be dated ahead of the server
        // clock that stamps it, so today closes the range -- with a day of
        // slack, which costs nothing and survives a forward clock step.
        //
        // Without it a floor alone leaves the whole lead in play, since every
        // future partition sits above it: on a table with two years of history
        // and a year ahead, 15 partitions of 37 rather than 2.
        $start = $date->modify( '-' . self::FACT_LOWER_BOUND_SLACK_DAYS . ' days' )->format( 'Ymd' );
        $end   = ( new \DateTimeImmutable( 'tomorrow' ) )->format( 'Ymd' );

        // A session dated in the future inverts the range, and BETWEEN would
        // then match nothing at all -- turning a summary into a zero rather
        // than a slow query. Refuse to bound instead.
        if ( $start > $end ) {

            return null;
        }

        return [ 'start' => $start, 'end' => $end ];
    }

    /** The most days factDateConstraint() lists one by one. */
    const FACT_DATE_IN_LIMIT = 64;

    /**
     * factDateRange() as a constraint array, ready to hand to a lookup.
     *
     * THE DAYS RATHER THAN THE RANGE, up to FACT_DATE_IN_LIMIT of them. The
     * tables it bounds are keyed (yyyymmdd, id), where `id = ? AND yyyymmdd
     * BETWEEN` reads every row of the range and `yyyymmdd IN (...)` is one
     * seek per day. A longer range, from a date far in the past, is a BETWEEN.
     *
     * Empty where no usable range exists, so a caller can pass it
     * unconditionally and simply get an unconstrained query.
     *
     * @param mixed $yyyymmdd  a stored date, any type
     * @return array{}|array{yyyymmdd: array{value: list<int>|array{start: string, end: string}, operator: string}}
     */
    public static function factDateConstraint( $yyyymmdd ) {

        $range = self::factDateRange( $yyyymmdd );

        if ( ! $range ) {

            return [];
        }

        $days = [];
        $day  = \DateTimeImmutable::createFromFormat( 'Ymd|', $range['start'] );

        while ( $day && $day->format( 'Ymd' ) <= $range['end'] && count( $days ) <= self::FACT_DATE_IN_LIMIT ) {

            $days[] = (int) $day->format( 'Ymd' );
            $day    = $day->modify( '+1 day' );
        }

        if ( $days && count( $days ) <= self::FACT_DATE_IN_LIMIT ) {

            return [ 'yyyymmdd' => [ 'value' => $days, 'operator' => 'in' ] ];
        }

        return [ 'yyyymmdd' => [ 'value' => $range, 'operator' => 'between' ] ];
    }

    /**
     * Work out the granularity a table is already using.
     *
     * Taken from the boundaries rather than the partition names. The names
     * encode the same dates -- p20261008 is the period starting on the 8th --
     * but a name is a label this class chose, while VALUES LESS THAN is what
     * MySQL enforces and what actually decides where a row goes. Where the two
     * could disagree, the boundary is the truth.
     *
     * Since every cut is a day of the month, the days a month is cut on are
     * exactly the granularity's entry in PARTITION_CUTS, so this is a lookup
     * rather than a calculation.
     *
     * Only the most recent month is considered. A table is allowed to be coarse
     * in history and finer over recent periods, and it is the recent end that
     * says what new periods should look like.
     *
     * @param string $table_name
     * @return string|null  null where it does not match a known scheme
     */
    public function inferPartitionGranularity( $table_name ) {

        $spans = $this->db->getPartitionSpans( $table_name );

        if ( ! $spans ) {

            return null;
        }

        $month = substr( $spans[ count( $spans ) - 1 ]['start'], 0, 6 );
        $days  = [];

        foreach ( $spans as $span ) {

            if ( substr( $span['start'], 0, 6 ) === $month ) {

                $days[] = (int) substr( $span['start'], 6, 2 );
            }
        }

        sort( $days );

        $month_start = \DateTimeImmutable::createFromFormat( 'Ymd|', $month . '01' );

        if ( ! $month_start ) {

            return null;
        }

        foreach ( self::PARTITION_CUTS as $granularity => $cuts ) {

            // Against the cuts this month can actually carry, not against the
            // constant: a 30-day month has no 31st, so a daily table's
            // boundaries are 1..30 and never equal PARTITION_CUTS['daily'].
            // For the three coarser schemes every cut is <= 22, so clamping is
            // a no-op.
            if ( $days === self::cutsForMonth( $month_start, $granularity ) ) {

                return $granularity;
            }
        }

        return null;
    }

    /**
     * Name the period one partition spans.
     *
     * A tiered table does not have "a granularity": recent months are cut at the
     * granularity in force, older ones have been merged into blocks of months or
     * years to stay inside the open-file budget. Describing such a table with one
     * word is wrong in both directions -- it either overstates the resolution of
     * the tail or understates the head.
     *
     * A sub-month period is named by the granularity whose cuts it falls between,
     * so the vocabulary is the one the reorganize command takes. Anything longer
     * is named by its own length.
     *
     * @param string $start      yyyymmdd
     * @param string $less_than  yyyymmdd
     * @return string
     */
    public static function describePartitionPeriod( $start, $less_than ) {

        $a = \DateTimeImmutable::createFromFormat( 'Ymd|', (string) $start );
        $b = \DateTimeImmutable::createFromFormat( 'Ymd|', (string) $less_than );

        if ( ! $a || ! $b || $b <= $a ) {

            return 'unknown';
        }

        // Whole months from the 1st: one calendar month is "monthly", and longer
        // blocks are named in years where they divide evenly, because that is how
        // the merged tail is built and how an operator thinks about it.
        if ( $a->format( 'd' ) === '01' && $b->format( 'd' ) === '01' ) {

            $months = ( (int) $b->format( 'Y' ) - (int) $a->format( 'Y' ) ) * 12
                    + ( (int) $b->format( 'n' ) - (int) $a->format( 'n' ) );

            if ( $months === 1 ) {

                return 'monthly';
            }

            if ( $months % 12 === 0 ) {

                $years = intdiv( $months, 12 );

                return $years === 1 ? '1 year' : $years . ' years';
            }

            return $months . ' months';
        }

        // Within a month: the granularity that cuts it here.
        foreach ( self::PARTITION_CUTS as $granularity => $cuts ) {

            $day  = (int) $a->format( 'j' );
            $at   = array_search( $day, $cuts, true );

            if ( $at === false ) {

                continue;
            }

            $next = isset( $cuts[ $at + 1 ] )
                  ? $a->setDate( (int) $a->format( 'Y' ), (int) $a->format( 'n' ), $cuts[ $at + 1 ] )
                  : $a->modify( 'first day of next month' );

            if ( $next->format( 'Ymd' ) === $b->format( 'Ymd' ) ) {

                return $granularity;
            }
        }

        return $a->diff( $b )->days . ' days';
    }

    /**
     * What a table's partitioning actually looks like right now.
     *
     * Reports the layout as tiers -- runs of adjacent partitions covering the
     * same length of time -- because that is the shape the commands produce and
     * a single granularity cannot describe it. The lead is separated out, since
     * how far the bounded partitions reach into the future is what determines
     * when the next rotate is due.
     *
     * Reads only metadata: no table is scanned. Catch-all contents are counted
     * separately, by the caller that wants them.
     *
     * @param string $table_name
     * @return array{partitioned: bool, spans: int, total: int, covers: array{start: string, end: string}|null, granularity: string|null, tiers: list<array{period: string, count: int, start: string, end: string}>, catch_all: string|null, through: string|null, ahead: int|null, lead: int}
     */
    public function describePartitionLayout( $table_name ) {

        $out = [
            'partitioned' => false,
            'spans'       => 0,
            'total'       => 0,
            'covers'      => null,
            'granularity' => null,
            'tiers'       => [],
            'catch_all'   => null,
            'through'     => null,
            'ahead'       => null,
            'lead'        => 0,
        ];

        $all = $this->db->listPartitions( $table_name );

        if ( ! $all ) {

            return $out;
        }

        $spans = $this->db->getPartitionSpans( $table_name );

        $out['partitioned'] = true;
        $out['total']       = count( $all );
        $out['spans']       = count( $spans );
        $out['catch_all']   = $this->db->getCatchAllPartition( $table_name );
        $out['granularity'] = $this->db->inferPartitionGranularity( $table_name );

        if ( ! $spans ) {

            // A catch-all and nothing else: everything is unbounded.
            return $out;
        }

        $out['covers']  = [ 'start' => $spans[0]['start'], 'end' => end( $spans )['less_than'] ];
        $out['through'] = end( $spans )['less_than'];

        $today = date( 'Ymd' );

        foreach ( $spans as $span ) {

            $period = self::describePartitionPeriod( $span['start'], $span['less_than'] );
            $last   = count( $out['tiers'] ) - 1;

            if ( $last >= 0 && $out['tiers'][ $last ]['period'] === $period ) {

                $out['tiers'][ $last ]['count']++;
                $out['tiers'][ $last ]['end'] = $span['less_than'];

            } else {

                $out['tiers'][] = [
                    'period' => $period,
                    'count'  => 1,
                    'start'  => $span['start'],
                    'end'    => $span['less_than'],
                ];
            }

            // Lead is the partitions that begin after today: periods bought in
            // advance, which is what running out of them costs.
            if ( $span['start'] > $today ) {

                $out['lead']++;
            }
        }

        $a = \DateTimeImmutable::createFromFormat( 'Ymd|', $today );
        $b = \DateTimeImmutable::createFromFormat( 'Ymd|', (string) $out['through'] );

        if ( $a && $b ) {

            // Negative where the top boundary is already behind us, which is the
            // state that matters: new rows are landing in the catch-all.
            $out['ahead'] = (int) $a->diff( $b )->format( '%r%a' );
        }

        return $out;
    }

    /**
     * The name of the catch-all partition, or null where there is none.
     *
     * @param string $table_name
     * @return string|null
     */
    public function getCatchAllPartition( $table_name ) {

        foreach ( $this->db->listPartitions( $table_name ) as $p ) {

            if ( strtoupper( $p['less_than'] ) === OWA_DTD_PARTITION_MAXVALUE ) {

                return $p['name'];
            }
        }

        return null;
    }

    /**
     * Merge a run of adjacent partitions into one.
     *
     * The inverse of extendPartitions(): that splits, this combines. It exists so
     * that partition count can be kept under the server's open-file budget
     * WITHOUT deleting anything -- old periods are coarsened rather than dropped.
     * A table can then hold decades of history in a few dozen files.
     *
     * The replacement keeps the p<yyyymmdd> naming, because getPartitionSpans()
     * recovers the first partition's lower bound from its name and
     * inferPartitionGranularity() reads the day-of-month out of it. A partition
     * named anything else reads back as having no usable start.
     *
     * The partitions must be adjacent and given in order: REORGANIZE requires the
     * replacement to tile exactly the range being replaced, so the new upper
     * bound has to be the last one's.
     *
     * @param string $table_name
     * @param list<string> $names  partition names, in ascending order
     * @param string $start      yyyymmdd the merged partition begins at (its name)
     * @param string $less_than  yyyymmdd upper bound -- the last partition's
     * @return bool
     */
    public function mergePartitions( $table_name, $names, $start, $less_than ) {

        if ( ! $this->db->supportsPartitioning() || count( $names ) < 2 ) {

            return false;
        }

        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name ) ) {

            return false;
        }

        foreach ( $names as $n ) {

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $n ) ) {

                return false;
            }
        }

        if ( ! preg_match( '/^\d{8}$/', (string) $start ) || ! preg_match( '/^\d{8}$/', (string) $less_than ) ) {

            return false;
        }

        $replacement = sprintf( OWA_DTD_PARTITION_LESS_THAN, 'p' . $start, $less_than );

        return (bool) $this->changePartitions( $table_name, sprintf(
            OWA_SQL_REORGANIZE_PARTITION, $table_name, implode( ',', $names ), $replacement
        ) );
    }

    /**
     * How old a period must be before it is eligible to be coarsened, in months.
     *
     * Inside this window partitions keep whatever granularity the table uses, so
     * recent reporting and recent retention stay precise. Outside it they may be
     * merged, because old periods are queried rarely and pruned in bulk.
     *
     * This is the code-level default. An installation overrides it with
     * OWA_PARTITION_DETAIL_MONTHS in owa-config.php; the commands read the
     * setting and pass the result in, so this value applies only to a direct
     * caller that supplies nothing.
     */
    const PARTITION_DETAIL_MONTHS = 36;

    /**
     * The old tier: blocks of whole calendar years from a year's January, the
     * last closing on $tail_limit rather than on a year end.
     *
     * @param int    $first_year
     * @param string $tail_limit  yyyymmdd the last block ends on (exclusive)
     * @param int    $block       years per block
     * @return PartitionRanges
     */
    public static function yearBlocks( $first_year, $tail_limit, $block ) {

        $ranges = [];
        $block  = max( 1, (int) $block );

        for ( $y = (int) $first_year; ; $y += $block ) {

            $end = ( $y + $block ) . '0101';

            if ( $end >= $tail_limit ) {

                $end = (string) $tail_limit;
            }

            $ranges[ 'p' . $y . '0101' ] = $end;

            if ( $end >= $tail_limit ) {

                break;
            }
        }

        return $ranges;
    }

    /**
     * Years per old-tier block: the smallest that keeps the table within the
     * limit, capped at PARTITION_MAX_YEARS_PER_BLOCK.
     *
     * @param int    $first_year  of the first old partition
     * @param string $tail_limit  where the old tier ends
     * @param int    $kept        partitions outside the old tier
     * @param int    $limit
     * @return int
     */
    public static function oldTierBlockYears( $first_year, $tail_limit, $kept, $limit ) {

        $years = max( 1, (int) ceil(
            ( strtotime( $tail_limit ) - strtotime( $first_year . '0101' ) ) / ( 86400 * 365.25 )
        ) );

        $block = 1;

        for ( ; $block < self::PARTITION_MAX_YEARS_PER_BLOCK; $block++ ) {

            if ( $kept + (int) ceil( $years / $block ) <= $limit ) {

                break;
            }
        }

        return $block;
    }

    /**
     * Replace a run of adjacent partitions with a different set of ranges.
     *
     * The general form of both merging and splitting: REORGANIZE requires only
     * that the replacements tile exactly the span they replace, so N partitions
     * can become one, or one can become N, or five can become three.
     *
     * Splitting matters as much as merging. Without it the layout would depend
     * on the order operations happened in rather than on the current settings --
     * a table coarsened under a tight budget would stay coarse after the budget
     * grew, while an identical installation partitioned fresh would not. Same
     * inputs, different result.
     *
     * @param string $table_name
     * @param list<string> $names   partitions to replace, ascending and adjacent
     * @param PartitionRanges $ranges  ascending, tiling the same span
     * @return bool
     */
    public function reshapePartitions( $table_name, $names, $ranges ) {

        if ( ! $this->db->supportsPartitioning() || ! $names || ! $ranges ) {

            return false;
        }

        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $table_name ) ) {

            return false;
        }

        foreach ( $names as $n ) {

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $n ) ) {

                return false;
            }
        }

        $parts = [];

        foreach ( $ranges as $name => $less_than ) {

            if ( ! preg_match( '/^[A-Za-z0-9_]+$/', (string) $name )
              || ! preg_match( '/^\d{8}$/', (string) $less_than ) ) {

                return false;
            }

            $parts[] = sprintf( OWA_DTD_PARTITION_LESS_THAN, $name, $less_than );
        }

        return (bool) $this->changePartitions( $table_name, sprintf(
            OWA_SQL_REORGANIZE_PARTITION, $table_name, implode( ',', $names ), implode( ', ', $parts )
        ) );
    }

    /**
     * Largest run of calendar years that may be merged into one partition.
     *
     * A cap, not a target. Without one, a budget that cannot be met would drive
     * the planner to collapse the entire tail into a single partition -- which
     * fits nothing better and destroys retention granularity for all of history,
     * since a partition can only be dropped as a unit. Better to return the best
     * plan available and report that it does not fit.
     */
    const PARTITION_MAX_YEARS_PER_BLOCK = 5;

    /**
     * Work out the tail layout the current settings imply, and how to get there
     * from whatever the table looks like now.
     *
     * The result is a function of the budget and the detail window ALONE, never
     * of the order operations happened in. That is deliberate: a table coarsened
     * under a tight budget must go back to finer blocks when the budget grows,
     * or an installation's layout would depend on its history rather than its
     * configuration, and two identical installations could differ permanently.
     *
     * So this both merges and splits. Blocks are whole calendar years, widened
     * only as far as the budget requires and never past
     * PARTITION_MAX_YEARS_PER_BLOCK -- a cap, because a budget that cannot be
     * met would otherwise drive everything into one partition, which fits no
     * better and means all of history ages out in a single drop.
     *
     * Nothing is ever deleted. The detail window is never touched.
     *
     * A RETENTION CUTOFF IS A BOUNDARY no merge crosses. A partition is dropped
     * whole, so one holding days on both sides of the cutoff keeps the older
     * ones until its last day ages out: up to a block of years. With a cutoff
     * in the old tier (see splitAtCutoff()), partitions wholly before it are
     * left for the drop, one straddling it is split at it, the rest of the
     * cutoff's year stays as it is, and only whole years after it are merged.
     *
     * @param string      $table_name
     * @param int         $limit          most partitions this table may have
     * @param int         $detail_months  periods newer than this are left alone
     * @param string|null $cutoff         yyyymmdd: the first day retention keeps; null keeps everything
     * @return array{operations: list<Reshape>, merges: list<Reshape>, projected: int, fits: bool, floor: int, block_years: int}
     */
    public function planPartitionCompaction( $table_name, $limit, $detail_months = self::PARTITION_DETAIL_MONTHS, $cutoff = null ) {

        $spans  = $this->db->getPartitionSpans( $table_name );
        $result = [
            'operations'  => [],
            'merges'      => [],
            'projected'   => count( $spans ),
            'fits'        => true,
            'floor'       => count( $spans ),
            'block_years' => 1,
        ];

        if ( ! $spans ) {

            return $result;
        }

        $boundary = date( 'Ymd', strtotime( date( 'Ym' ) . '01 -' . (int) $detail_months . ' months' ) );

        $old = [];

        foreach ( $spans as $span ) {

            if ( (string) $span['less_than'] <= $boundary ) {

                $old[] = $span;
            }
        }

        $before_cutoff = [];

        if ( $cutoff !== null && $old ) {

            $split = self::splitAtCutoff( $old, (string) $cutoff );

            $old                  = $split['tail'];
            $before_cutoff        = $split['operations'];
            $result['operations'] = $before_cutoff;
            $result['merges']     = $before_cutoff;
        }

        // Partitions the drop is about to take are not counted; those a split adds are.
        $kept = count( $spans ) - count( $old )
              - ( isset( $split ) ? $split['doomed'] - $split['added'] : 0 );

        $result['projected'] = $kept + count( $old );
        $result['floor']     = $kept + ( $old ? 1 : 0 );

        if ( ! $old ) {

            $result['fits'] = $result['projected'] <= $limit;

            return $result;
        }

        // The tail runs from the first old partition to wherever the last one
        // ends -- which is usually mid-year, because the detail window opens on
        // a month rather than a January. The final block therefore closes on
        // that boundary rather than on a year end, or the remaining months would
        // belong to no block and stay unmerged.
        $tail_start = (int) substr( $old[0]['start'], 0, 4 );
        $tail_limit = (string) $old[ count( $old ) - 1 ]['less_than'];

        // Smallest block size that fits, capped. One year is the finest the tail
        // is ever cut to: below that it is the detail window's job.
        $block = self::oldTierBlockYears( $tail_start, $tail_limit, $kept, $limit );

        $result['block_years'] = $block;

        // The layout those settings imply.
        $target = self::yearBlocks( $tail_start, $tail_limit, $block );

        $result['projected'] = $kept + count( $target );
        $result['fits']      = $result['projected'] <= $limit;

        // Already in that shape? Then there is nothing to do, however many times
        // this runs.
        $current = [];

        foreach ( $old as $span ) {

            $current[ $span['name'] ] = (string) $span['less_than'];
        }

        if ( $current === array_map( 'strval', $target ) ) {

            return $result;
        }

        // One reorganisation per target block, over whichever partitions it
        // covers. That merges where the tail is too fine and splits where it is
        // too coarse, in the same operation.
        $names_by_block = [];

        foreach ( $target as $name => $less_than ) {

            $from = substr( $name, 1 );

            $names_by_block[ $name ] = [
                'names'     => [],
                'start'     => $from,
                'less_than' => $less_than,
                'ranges'    => [ $name => $less_than ],
            ];
        }

        foreach ( $old as $span ) {

            foreach ( $names_by_block as $name => &$block_def ) {

                // A partition belongs to the block its start falls in. A block
                // that is coarser than the target is claimed by the first target
                // block it overlaps, and split by the reorganisation.
                if ( (string) $span['start'] >= $block_def['start']
                  && (string) $span['start'] < $block_def['less_than'] ) {

                    $block_def['names'][] = $span['name'];

                    if ( (string) $span['less_than'] > $block_def['less_than'] ) {

                        // This partition reaches past the block, so the block and
                        // everything it spans are reshaped together.
                        $block_def['less_than'] = (string) $span['less_than'];
                    }

                    break;
                }
            }

            unset( $block_def );
        }

        foreach ( $names_by_block as $name => $block_def ) {

            if ( ! $block_def['names'] ) {

                continue;
            }

            // Ranges covering exactly what the named partitions span.
            $ranges = [];

            for ( $y = (int) substr( $block_def['start'], 0, 4 ); ; $y += $block ) {

                $end = ( $y + $block ) . '0101';

                if ( $end >= $block_def['less_than'] ) {

                    $end = (string) $block_def['less_than'];
                }

                $ranges[ 'p' . $y . '0101' ] = $end;

                if ( $end >= $block_def['less_than'] ) {

                    break;
                }
            }

            // No change needed where the shape already matches.
            $same = ( count( $block_def['names'] ) === count( $ranges ) );

            if ( $same ) {

                $i = 0;

                foreach ( $ranges as $rname => $rless ) {

                    if ( $block_def['names'][ $i ] !== $rname ) {

                        $same = false;

                        break;
                    }

                    $i++;
                }
            }

            if ( $same ) {

                continue;
            }

            $result['operations'][] = [
                'names'  => $block_def['names'],
                'ranges' => $ranges,
                'start'  => $block_def['start'],
                'less_than' => $block_def['less_than'],
            ];
        }

        $result['operations'] = array_merge( $before_cutoff, $result['operations'] );

        // Kept for callers that only care about the coarsening case.
        $result['merges'] = $result['operations'];

        return $result;
    }

    /**
     * The old tier around a retention cutoff.
     *
     * A partition ending on or before the cutoff is the drop's, and is left as
     * it is. One straddling the cutoff and longer than a calendar month -- a
     * merged block -- is split: everything before the cutoff in one partition,
     * then calendar months to the next January, then whatever it held after
     * that in one more, which a later run plans as years. The rest of the
     * cutoff's year is left as it is, so as the cutoff moves through it the drop
     * takes a month at a time and nothing is rewritten. A partition within one
     * month is the finest the old tier gets and is not split, as a monthly table
     * is not. Whole years from the next January on are the tail, merged as
     * before.
     *
     * @param list<Span> $old  the old tier's spans, ascending
     * @param string $cutoff  yyyymmdd
     * @return array{tail: list<Span>, operations: list<Reshape>, doomed: int, added: int}
     *         doomed: spans the drop takes; added: partitions the split adds
     */
    public static function splitAtCutoff( array $old, $cutoff ) {

        $cutoff = (string) $cutoff;
        $next   = substr( $cutoff, 4 ) === '0101' ? $cutoff : ( (int) substr( $cutoff, 0, 4 ) + 1 ) . '0101';
        $out    = [ 'tail' => [], 'operations' => [], 'doomed' => 0, 'added' => 0 ];
        $names  = array_column( $old, 'name' );

        foreach ( $old as $span ) {

            $start     = (string) $span['start'];
            $less_than = (string) $span['less_than'];

            if ( $less_than <= $cutoff ) {

                $out['doomed']++;

                continue;
            }

            if ( $start >= $next ) {

                $out['tail'][] = $span;

                continue;
            }

            // Inside the cutoff's year, or straddling it within one calendar month: as it is.
            if ( $start >= $cutoff
                 || $less_than <= date( 'Ymd', strtotime( substr( $start, 0, 6 ) . '01 +1 month' ) ) ) {

                continue;
            }

            // Straddling the cutoff, and longer than a month.
            $ranges = [ 'p' . $start => $cutoff ];
            $day    = $cutoff;

            while ( $day < $less_than && $day < $next ) {

                $month_end = date( 'Ymd', strtotime( substr( $day, 0, 6 ) . '01 +1 month' ) );
                $end       = min( $month_end, $less_than );

                $ranges[ 'p' . $day ] = $end;
                $day = $end;
            }

            if ( $day < $less_than ) {

                $ranges[ 'p' . $day ] = $less_than;
            }

            // A name another partition already has would make the statement fail.
            $clash = array_intersect( array_keys( $ranges ), array_diff( $names, [ $span['name'] ] ) );

            if ( $clash ) {

                continue;
            }

            $out['operations'][] = [
                'names'     => [ $span['name'] ],
                'ranges'    => $ranges,
                'start'     => $start,
                'less_than' => $less_than,
            ];

            // The part before the cutoff is the drop's.
            $out['doomed']++;
            $out['added'] += count( $ranges ) - 1;
        }

        return $out;
    }

    /**
     * Add whatever partitions are missing between the last boundary and a date.
     *
     * The new periods are cut out of the catch-all, which is the only partition
     * holding anything above the last boundary, and a fresh catch-all is put
     * back on the end so writes beyond the new range are still accepted. Where
     * the catch-all is empty -- the normal case when this is run often enough --
     * the rewrite moves no rows.
     *
     * Doing nothing when the range is already covered is what makes this safe
     * to run on a schedule: the result depends on the date, not on how many
     * times it has run.
     *
     * @param string $table_name
     * @param string $granularity
     * @param string $through      yyyymmdd the partitions must reach
     * @param bool   $dry_run
     * @return array{added: list<string>, planned: int, top: string|null, covered: bool}
     */
    public function extendPartitions( $table_name, $granularity, $through, $dry_run = false ) {

        $result = [ 'added' => [], 'planned' => 0, 'top' => null, 'covered' => false ];

        $spans     = $this->db->getPartitionSpans( $table_name );
        $catch_all = $this->db->getCatchAllPartition( $table_name );

        if ( ! $spans || ! $catch_all ) {

            return $result;
        }

        $top = $spans[ count( $spans ) - 1 ]['less_than'];

        $result['top'] = (string) $top;

        if ( (string) $top >= (string) $through ) {

            $result['covered'] = true;

            return $result;
        }

        $ranges = self::makePartitionRangesForSpan( $top, $through, $granularity );

        if ( ! $ranges ) {

            return $result;
        }

        $result['planned'] = count( $ranges );

        if ( $dry_run ) {

            $result['added'] = array_keys( $ranges );

            return $result;
        }

        // The replacements must tile exactly what they replace, and the
        // catch-all reaches to MAXVALUE, so one has to go back on the end.
        $parts = [];

        foreach ( $ranges as $name => $less_than ) {

            $parts[] = sprintf( OWA_DTD_PARTITION_LESS_THAN, $name, $less_than );
        }

        $parts[] = sprintf( OWA_DTD_PARTITION_LESS_THAN, $catch_all, OWA_DTD_PARTITION_MAXVALUE );

        $sql = sprintf(
            OWA_SQL_REORGANIZE_PARTITION, $table_name, $catch_all, implode( ', ', $parts )
        );

        if ( $this->changePartitions( $table_name, $sql ) ) {

            $result['added'] = array_keys( $ranges );
        }

        return $result;
    }

    /**
     * Give a table dated partitions reaching back to a day.
     *
     * The first partition has no lower bound, so rows older than its start are
     * already accepted -- into a partition whose name says it begins later.
     * Retention would read them as the first partition's period, and a cube
     * build refuses to rebuild a range no dated partition covers. That first
     * partition is split to start at $from.
     *
     * In the shape planPartitionCompaction() keeps, so the next rotation has
     * nothing to rewrite: months within the detail window, and before it
     * blocks of whole years, as wide as the limit needs. Ten years back is
     * seven blocks and the window's months, not a hundred and twenty months.
     *
     * Done before older rows are written it moves nothing but the first
     * partition's own rows. A table already reaching $from is left alone.
     *
     * @param string $table_name
     * @param string $from           yyyymmdd the partitions must reach back to
     * @param int    $detail_months  how much recent history stays monthly
     * @param int    $limit          the partition ceiling
     * @param bool   $dry_run
     * @return array{added: list<string>, start: string|null, covered: bool}
     */
    public function extendPartitionsBack( $table_name, $from, $detail_months = self::PARTITION_DETAIL_MONTHS,
            $limit = self::PARTITION_COUNT_LIMIT, $dry_run = false ) {

        $result = [ 'added' => [], 'start' => null, 'covered' => false ];

        $spans = $this->db->getPartitionSpans( $table_name );

        if ( ! $spans || ! preg_match( '/^\d{8}$/', (string) $from ) ) {

            return $result;
        }

        $first = $spans[0];

        $result['start'] = $first['start'];

        if ( (string) $first['start'] <= (string) $from ) {

            $result['covered'] = true;

            return $result;
        }

        $ranges = self::backRanges( $from, (string) $first['less_than'], count( $spans ) - 1,
            $detail_months, $limit );

        if ( ! $ranges ) {

            return $result;
        }

        if ( $dry_run || $this->db->reorganizePartitions( $table_name, [ $first['name'] ], $ranges ) ) {

            $result['added'] = array_values( array_diff( array_keys( $ranges ), [ $first['name'] ] ) );
        }

        return $result;
    }

    /**
     * The ranges replacing a first partition that ends on $less_than, reaching
     * back to $from.
     *
     * Years before the detail window, as planPartitionCompaction() would cut
     * them: blocks sized against every partition that is not in the old tier.
     * Where the first partition itself ends inside the old tier, its blocks end
     * on its bound and the next rotation merges them with the rest.
     *
     * @param string $from
     * @param string $less_than  the first partition's upper bound
     * @param int    $others     partitions after the first
     * @param int    $detail_months
     * @param int    $limit
     * @return PartitionRanges
     */
    public static function backRanges( $from, $less_than, $others, $detail_months, $limit ) {

        $boundary = date( 'Ymd', strtotime( date( 'Ym' ) . '01 -' . (int) $detail_months . ' months' ) );

        if ( (string) $from >= $boundary ) {

            return self::makePartitionRangesForSpan( substr( $from, 0, 6 ) . '01', $less_than, 'monthly' );
        }

        $tail_limit = min( $boundary, (string) $less_than );
        $fine       = $less_than > $boundary
            ? self::makePartitionRangesForSpan( $boundary, $less_than, 'monthly' )
            : [];

        $first_year = (int) substr( $from, 0, 4 );
        $block      = self::oldTierBlockYears( $first_year, $tail_limit, $others + count( $fine ), $limit );

        return array_merge( self::yearBlocks( $first_year, $tail_limit, $block ), $fine );
    }

    /**
     * Which partitions hold only data older than a cutoff.
     *
     * A partition is droppable only when everything in it precedes the cutoff,
     * so a partition straddling that date is kept: dropping it would remove
     * data on or after the date, which is more than was asked for. With
     * partitions being periods rather than days that means the boundary actually
     * reached is usually earlier than the one requested, so it is reported --
     * 'effective' is the date before which data no longer exists once these are
     * dropped.
     *
     * The catch-all is never droppable: it has no upper bound, and it holds
     * current traffic.
     *
     * A cutoff later than today is clamped to today. Data up to and including
     * today is being written right now, so no cutoff may reach it -- a date in
     * the future is a mistyped year, not a request to discard the current
     * period. With the cutoff clamped, the partition holding today straddles it
     * and is kept by the rule above, which leaves exactly the current period in
     * place. 'requested' reports the original where it differed, so the caller
     * can say the date was not taken literally.
     *
     * @param string $table_name
     * @param int|string $older_than_yyyymmdd
     * @return array{drop: list<string>, effective: string|null, straddling: Span|null, requested: string|null}
     */
    public function getDroppablePartitions( $table_name, $older_than_yyyymmdd ) {

        $drop       = [];
        $effective  = null;
        $straddling = null;
        $cutoff     = (string) $older_than_yyyymmdd;
        $requested  = null;
        $today      = date( 'Ymd' );

        if ( $cutoff > $today ) {

            $requested = $cutoff;
            $cutoff    = $today;
        }

        foreach ( $this->db->getPartitionSpans( $table_name ) as $span ) {

            if ( (string) $span['less_than'] <= $cutoff ) {

                $drop[]    = $span['name'];
                $effective = (string) $span['less_than'];

                continue;
            }

            // The first partition that reaches past the cutoff. Report it when
            // it also holds older rows, so the caller can say why the boundary
            // reached is earlier than the one asked for.
            if ( $straddling === null && (string) $span['start'] < $cutoff ) {

                $straddling = $span;
            }
        }

        return [
            'drop'       => $drop,
            'effective'  => $effective,
            'straddling' => $straddling,
            'requested'  => $requested,
        ];
    }

    /**
     * Change a table's partition granularity.
     *
     * Existing partitions and target ranges are walked together and grouped
     * into the smallest chunks whose boundaries agree, so each REORGANIZE
     * rewrites only the periods it has to. A partition that already matches the
     * target exactly is left untouched, which makes re-running this a no-op.
     *
     * A range restricts it to part of the table, which is how an installation
     * ends up coarse for old data and fine for recent without carrying a
     * partition -- and so a file -- for every period of its whole history. The
     * range is snapped outwards to the boundaries of the partitions it touches,
     * since a partition can only be rewritten whole.
     *
     * A SKIP RANGE is left exactly as it is, and is how the reporting cube keeps
     * the daily part of its lead. Those are ordinary one-day partitions, so
     * every filter here would admit them and a change of granularity would
     * merge them away -- rewriting live rows, which partition-rotate would then
     * rewrite a second time putting them back. Skipping splits the selection
     * into more than one run, and each run is planned on its own; planning
     * across the hole would try to merge partitions from either side of it into
     * one.
     *
     * @param string      $table_name
     * @param string      $granularity  quarter-month|half-month|monthly
     * @param bool        $dry_run      report the statements without running them
     * @param string|null $from         first day to convert, yyyymmdd
     * @param string|null $to           first day not to convert, yyyymmdd
     * @param array{start: string, less_than: string}|null $skip  to leave untouched
     * @return array{changed: list<string>, skipped: int, failed: list<string>, planned: int}
     */
    public function repartitionTable( $table_name, $granularity, $dry_run = false, $from = null, $to = null, $skip = null ) {

        $result = [ 'changed' => [], 'skipped' => 0, 'failed' => [], 'planned' => 0 ];

        $spans = $this->db->getPartitionSpans( $table_name );

        if ( ! $spans ) {

            return $result;
        }

        // Leave coarsened history alone unless a range explicitly asks for it.
        //
        // A tail block spans whole years by design, to keep the partition count
        // within the server's open-file budget. Refining it back to the table's
        // granularity would undo that -- on a ten-year table, sixty-odd
        // partitions become nearly three hundred -- and would be the opposite of
        // what compaction just did. A partition covering at most one calendar
        // month is a normal period and is fair game; anything wider is a block.
        if ( $from === null && $to === null ) {

            $fine = [];

            foreach ( $spans as $span ) {

                $month_after = date( 'Ymd', strtotime( substr( $span['start'], 0, 6 ) . '01 +1 month' ) );

                if ( (string) $span['less_than'] <= $month_after ) {

                    $fine[] = $span;
                }
            }

            $spans = $fine;

            if ( ! $spans ) {

                return $result;
            }
        }

        // Keep only the partitions the range touches. A partition is rewritten
        // whole or not at all, so one that merely overlaps the range is
        // included and the range effectively widens to its boundaries.
        if ( $from !== null || $to !== null ) {

            $wanted = [];

            foreach ( $spans as $span ) {

                if ( $to !== null && (string) $span['start'] >= (string) $to ) {

                    continue;
                }

                if ( $from !== null && (string) $span['less_than'] <= (string) $from ) {

                    continue;
                }

                $wanted[] = $span;
            }

            $spans = $wanted;

            if ( ! $spans ) {

                return $result;
            }
        }

        // Leave the skip range exactly as it is.
        if ( $skip ) {

            $kept = [];

            foreach ( $spans as $span ) {

                if ( (string) $span['start'] >= (string) $skip['start']
                  && (string) $span['less_than'] <= (string) $skip['less_than'] ) {

                    continue;
                }

                $kept[] = $span;
            }

            $spans = $kept;

            if ( ! $spans ) {

                return $result;
            }
        }

        // One run per contiguous stretch. A skip range leaves a hole, and a
        // target planned across it would merge partitions from either side.
        $runs = [];
        $run  = [];

        foreach ( $spans as $span ) {

            if ( $run && (string) $run[ count( $run ) - 1 ]['less_than'] !== (string) $span['start'] ) {

                $runs[] = $run;
                $run    = [];
            }

            $run[] = $span;
        }

        $runs[] = $run;

        $total    = count( $this->db->getPartitionSpans( $table_name ) );
        $selected = count( $spans );
        $targets  = [];

        foreach ( $runs as $i => $one ) {

            $t = self::makePartitionRangesForSpan(
                $one[0]['start'], $one[ count( $one ) - 1 ]['less_than'], $granularity );

            if ( ! $t ) {

                continue;
            }

            $targets[ $i ] = $t;
        }

        if ( ! $targets ) {

            return $result;
        }

        // What the table would end up with: every converted run, plus whatever
        // the filters and the skip range left alone.
        $planned = $total - $selected;

        foreach ( $targets as $t ) {

            $planned += count( $t );
        }

        $result['planned'] = $planned;

        foreach ( $targets as $i => $target ) {

            $this->repartitionRun( $table_name, $runs[ $i ], $target, $dry_run, $result );
        }

        return $result;
    }

    /**
     * Reshape one contiguous run of partitions into one target shape.
     *
     * @param string $table_name
     * @param list<Span> $spans    the run, in order
     * @param PartitionRanges $target
     * @param bool   $dry_run
     * @param array{changed: list<string>, skipped: int, failed: list<string>, planned: int} $result  accumulated across runs
     * @return void
     */
    private function repartitionRun( $table_name, array $spans, array $target, $dry_run, array &$result ) {

        // Cut only where both sequences agree on a boundary. Every such cut
        // consumes at least one partition from each side, and the span end is
        // always shared, so this terminates.
        $existing_bounds = [];

        foreach ( $spans as $s ) {

            $existing_bounds[ (string) $s['less_than'] ] = true;
        }

        $cuts = [];

        foreach ( $target as $less_than ) {

            if ( isset( $existing_bounds[ (string) $less_than ] ) ) {

                $cuts[] = (string) $less_than;
            }
        }

        $i = 0; // index into $spans
        $j = 0; // index into target, as a list
        $t_names = array_keys( $target );

        foreach ( $cuts as $cut ) {

            $from = [];
            $into = [];

            while ( $i < count( $spans ) && (string) $spans[ $i ]['less_than'] <= $cut ) {

                $from[] = $spans[ $i ]['name'];
                $i++;
            }

            while ( $j < count( $t_names ) && (string) $target[ $t_names[ $j ] ] <= $cut ) {

                $into[ $t_names[ $j ] ] = $target[ $t_names[ $j ] ];
                $j++;
            }

            if ( ! $from || ! $into ) {

                continue;
            }

            // Already in the target shape: nothing to rewrite.
            if ( count( $from ) === 1 && count( $into ) === 1 && key( $into ) === $from[0] ) {

                $result['skipped']++;
                continue;
            }

            if ( $dry_run ) {

                $result['changed'][] = sprintf( '%s -> %s', implode( ',', $from ), implode( ',', array_keys( $into ) ) );
                continue;
            }

            if ( $this->db->reorganizePartitions( $table_name, $from, $into ) ) {

                $result['changed'][] = sprintf( '%s -> %s', implode( ',', $from ), implode( ',', array_keys( $into ) ) );

            } else {

                $result['failed'][] = implode( ',', $from );
            }
        }
    }
}
