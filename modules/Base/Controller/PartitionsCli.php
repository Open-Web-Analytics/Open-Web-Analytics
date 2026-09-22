<?php
namespace OWA\Module\Base\Controller;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Shared behaviour for the partition commands.
 *
 * The partitioned tables come from the module's entity registry rather than a
 * list here, so the set stays correct as entities are added.
 */
abstract class PartitionsCli extends \OWA\Core\Controller\Cli {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    /**
     * The partitioned tables, by name.
     *
     * Selected by whether the entity DECLARES a partition column, not by
     * whether it extends Entity\FactTable. Every fact table calls
     * setPartitionColumn('yyyymmdd') in that base constructor, so the set is
     * unchanged for them -- but v2's owa_event_raw is partitioned on the same
     * scheme while deliberately NOT extending FactTable, whose constructor
     * hard-codes the star's ten dimension foreign keys. Asking about the
     * property that actually matters lets it join the set instead of needing a
     * second copy of every partition command.
     *
     * @param string|null $only  restrict to one table
     * @return string[]
     */
    protected function factTables( $only = null ) {

        $s      = \OWA\Core\CoreAPI::serviceSingleton();
        $ns     = \OWA\Core\CoreAPI::getSetting( 'base', 'ns' );
        $tables = array();

        foreach ( $s->modules['base']->getEntities() as $name ) {

            $entity = \OWA\Core\CoreAPI::entityFactory( 'base.' . $name );

            if ( ! method_exists( $entity, 'getPartitionColumn' )
                 || ! $entity->getPartitionColumn() ) {

                continue;
            }

            $table = $ns . $name;

            if ( $only && $table !== $only ) {

                continue;
            }

            $tables[] = $table;
        }

        if ( $only && ! $tables ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                '"%s" is not a partitioned table. Partitioning applies to: %s.',
                $only, implode( ', ', $this->factTables() )
            ) );
        }

        return $tables;
    }

    /**
     * Resolve a retention cutoff.
     *
     * Accepts a date as yyyymmdd, or a period back from today -- '12months',
     * '18m', '2years', '90days'. Operators think in retention periods, and a
     * fixed date in a scheduled job silently stops pruning the moment it is
     * passed.
     *
     * @param string $value
     * @return string|null  yyyymmdd, or null if it cannot be read
     */
    protected function resolveCutoff( $value ) {

        $value = trim( (string) $value );

        if ( preg_match( '/^\d{8}$/', $value ) ) {

            // Reject a date that is not one, rather than partitioning against it.
            $d = \DateTimeImmutable::createFromFormat( 'Ymd|', $value );

            return ( $d && $d->format( 'Ymd' ) === $value ) ? $value : null;
        }

        if ( preg_match( '/^(\d+)\s*(day|days|d|month|months|m|year|years|y)$/i', $value, $m ) ) {

            $n    = (int) $m[1];
            $unit = strtolower( $m[2] );

            if ( $n < 1 ) {

                return null;
            }

            $interval = ( $unit[0] === 'd' ) ? 'day' : ( ( $unit[0] === 'm' ) ? 'month' : 'year' );

            return ( new \DateTimeImmutable( 'today' ) )->modify( sprintf( '-%d %s', $n, $interval ) )->format( 'Ymd' );
        }

        return null;
    }

    /**
     * The most partitions one table may be given in this run.
     *
     * Each partition is a file, and InnoDB caps how many tablespaces it holds
     * open through innodb_open_files -- a cap shared with every table already
     * on the server. Where that can be read, the budget is derived from what is
     * actually left rather than guessed: half the spare slots, divided by the
     * number of tables about to be partitioned. Half, because the reading is a
     * snapshot and the schema will grow.
     *
     * Where it cannot be read the constant stands in.
     *
     * @param int $table_count  tables this run will partition
     * @return array  limit, and how it was arrived at
     */
    protected function partitionLimit( $table_count ) {

        // Partitioning is shaped by settings, not by command arguments: how much
        // history stays finely partitioned and how much of the server's open-file
        // budget this may claim are properties of an installation, and an
        // operator should not be able to change them per invocation. They are set
        // once with a constant in owa-config.php -- see
        // owa_settings::applyConfigConstants().
        $stated = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'partition_max_partitions' );

        if ( $stated > 0 ) {

            return array(
                'limit'  => $stated,
                'reason' => 'set by OWA_PARTITION_MAX_PARTITIONS',
            );
        }

        $spare = \OWA\Core\CoreAPI::dbSingleton()->getPartitionBudget();

        if ( $spare === null ) {

            return array(
                'limit'  => \OWA\Core\Db::PARTITION_COUNT_LIMIT,
                'reason' => 'default limit; this server does not report its open-file budget',
            );
        }

        $reserve = max( 1, (int) \OWA\Core\CoreAPI::getSetting( 'base', 'partition_budget_reserve' ) );
        $floor   = max( 1, (int) \OWA\Core\CoreAPI::getSetting( 'base', 'partition_min_limit' ) );

        $limit = max( $floor, intdiv( $spare, $reserve * max( 1, $table_count ) ) );

        return array(
            'limit'  => $limit,
            'reason' => sprintf(
                '%d spare open-file slots on this server, 1/%d of them shared across %d table(s)',
                $spare, $reserve, $table_count
            ),
        );
    }

    /**
     * The budget, sized against every fact table rather than the ones this run
     * happens to touch.
     *
     * The open-file budget is a property of the server and the schema: the other
     * fact tables hold their partitions open whether or not this invocation
     * mentions them. Sizing it from the filtered set would hand a single-table
     * run the whole allowance -- so `table=owa_session` would report, and permit,
     * several times the partitions that the same command without a filter would.
     *
     * @return array  from partitionLimit()
     */
    protected function factTableBudget() {

        return $this->partitionLimit( max( 1, count( $this->factTables() ) ) );
    }

    /**
     * How recent a period must be to keep its fine granularity. Older ones may be
     * merged to stay within the budget.
     *
     * @return int months
     */
    protected function detailMonths() {

        $months = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'partition_detail_months' );

        return $months > 0 ? $months : \OWA\Core\Db::PARTITION_DETAIL_MONTHS;
    }

    /**
     * Refuse a plan that would leave a table with more partitions than the
     * server has the open files to carry.
     *
     * @param string $table
     * @param int    $planned
     * @param array  $budget   from partitionLimit()
     * @return bool  true when it is safe to proceed
     */
    protected function withinPartitionBudget( $table, $planned, $budget ) {

        if ( $planned <= $budget['limit'] || $this->getParam( 'force' ) ) {

            return true;
        }

        \OWA\Core\CoreAPI::notice( sprintf(
            '%s: refusing to create %d partitions (limit %d -- %s). Each partition is a file, and '
          . 'past the budget MySQL closes and reopens tablespaces under load, which slows '
          . 'everything on the instance. Lower OWA_PARTITION_DETAIL_MONTHS so less history is '
          . 'kept at full granularity, choose a coarser granularity, or set '
          . 'OWA_PARTITION_MAX_PARTITIONS if you have checked innodb_open_files yourself.',
            $table, $planned, $budget['limit'], $budget['reason']
        ) );

        return false;
    }

    /**
     * Bring one table's lead up to a date, reporting what it did.
     *
     * Shared by partition-init and partition-rotate so that extending is
     * described the same way whichever command asked for it.
     *
     * @param string $table
     * @param string $granularity
     * @param string $through
     * @param array  $budget  from partitionLimit()
     * @param bool   $dry_run
     * @return bool  false only where it wanted to act and could not
     */
    protected function extendTableLead( $table, $granularity, $through, $budget, $dry_run ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $plan = $db->extendPartitions( $table, $granularity, $through, true );

        if ( $plan['covered'] ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s: already covered through %s; nothing to add.', $table, $plan['top']
            ) );

            return true;
        }

        if ( ! $plan['planned'] ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s: partitioned, but its layout could not be read; skipping.', $table
            ) );

            return false;
        }

        if ( ! $this->withinPartitionBudget(
            $table, count( $db->getPartitionSpans( $table ) ) + $plan['planned'], $budget
        ) ) {

            return false;
        }

        if ( $dry_run ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s: would add %d %s partition(s), extending %s to %s.',
                $table, $plan['planned'], $granularity, $plan['top'], $through
            ) );

            return true;
        }

        $done = $db->extendPartitions( $table, $granularity, $through );

        if ( ! $done['added'] ) {

            // fail(), not notice(): Db::query() swallows SQL errors and returns
            // falsy, so a rotate whose ALTER TABLE the server rejected would
            // otherwise be recorded as a success while the lead quietly expired.
            $this->fail( sprintf( '%s: FAILED to extend; see the database error above.', $table ) );

            return false;
        }

        \OWA\Core\CoreAPI::notice( sprintf(
            '%s: added %d %s partition(s), now covered through %s.',
            $table, count( $done['added'] ), $granularity, $through
        ) );

        return true;
    }

    /**
     * Drop one table's partitions that hold only data older than a cutoff.
     *
     * Shared by partition-drop and partition-rotate.
     *
     * @param string $table
     * @param string $cutoff
     * @param bool   $dry_run
     * @return int  partitions dropped, or that would be
     */
    protected function dropOlderThan( $table, $cutoff, $dry_run ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $plan  = $db->getDroppablePartitions( $table, $cutoff );
        $spans = $db->getPartitionSpans( $table );

        if ( ! $plan['drop'] ) {

            \OWA\Core\CoreAPI::notice( sprintf( '%s: nothing older than %s.', $table, $cutoff ) );

            return 0;
        }

        // A cutoff in the future is a mistyped year. It is honoured as today
        // rather than refused, which keeps the current period and discards the
        // rest -- but say so, since the date was not taken literally.
        if ( $plan['requested'] ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s: %s is in the future; treating it as today (%s). Data being collected now '
              . 'is never dropped, so the current period is kept.',
                $table, $plan['requested'], date( 'Ymd' )
            ) );
        }

        // Every bounded partition going means all history goes; the catch-all
        // and the period holding today remain, so collection continues. Still
        // worth confirming -- it is usually a cutoff meant to be years earlier.
        if ( count( $plan['drop'] ) === count( $spans ) && ! $this->getParam( 'force' ) ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s: %s would drop all %d historical partition(s), leaving only what is being '
              . 'collected now. Everything before %s would be gone. If that is intended, '
              . 're-run with --force.',
                $table, $cutoff, count( $plan['drop'] ), $plan['effective']
            ) );

            return 0;
        }

        $dropped = count( $plan['drop'] );

        if ( $dry_run ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s: would drop %d partition(s) [%s]; data before %s would be gone.',
                $table, $dropped, implode( ', ', $plan['drop'] ), $plan['effective']
            ) );

        } else {

            $dropped = 0;

            foreach ( $plan['drop'] as $partition ) {

                if ( $db->dropPartition( $table, $partition ) ) {

                    $dropped++;

                } else {

                    $this->fail( sprintf( '%s: failed to drop %s.', $table, $partition ) );
                }
            }

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s: dropped %d partition(s). Data before %s no longer exists.',
                $table, $dropped, $plan['effective']
            ) );
        }

        if ( $plan['straddling'] ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s: kept %s (%s to %s) -- it also holds data on or after %s, so the boundary reached is %s.',
                $table, $plan['straddling']['name'], $plan['straddling']['start'],
                $plan['straddling']['less_than'], $cutoff, $plan['effective']
            ) );
        }

        return $dropped;
    }

    /**
     * Merge old periods so the table fits its partition budget, deleting nothing.
     *
     * This is what decouples partition count from retention. Without it the only
     * way back under an open-file budget is to drop history, which turns a
     * resource limit into a data-loss decision.
     *
     * @param string $table
     * @param array  $budget  from partitionLimit()
     * @param bool   $dry_run
     * @return int merges performed, or that would be
     */
    protected function compactTable( $table, $budget, $dry_run ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $plan = $db->planPartitionCompaction( $table, $budget['limit'], $this->detailMonths() );

        if ( ! $plan['operations'] ) {

            // Worth saying when the table cannot fit even fully merged: the
            // remedy is a shorter detail window, and nothing else the operator
            // does to this command will help.
            if ( ! $plan['fits'] ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: %d partitions exceeds the budget of %d and cannot be merged below %d, '
                  . 'because everything within the last %d months is kept at full granularity. '
                  . 'Lower OWA_PARTITION_DETAIL_MONTHS to reduce it further.',
                    $table, $plan['projected'], $budget['limit'], $plan['floor'], $this->detailMonths()
                ) );
            }

            return 0;
        }

        $first = $plan['operations'][0];
        $last  = $plan['operations'][ count( $plan['operations'] ) - 1 ];

        \OWA\Core\CoreAPI::notice( sprintf(
            '%s: %s %d group(s) of old periods covering %s to %s into blocks of %d year(s), '
          . 'leaving %d partitions. No data is deleted.',
            $table, $dry_run ? 'would reshape' : 'reshaping', count( $plan['operations'] ),
            $first['start'], $last['less_than'], $plan['block_years'], $plan['projected']
        ) );

        if ( $dry_run ) {

            return count( $plan['operations'] );
        }

        $done = 0;

        foreach ( $plan['operations'] as $op ) {

            if ( $db->reshapePartitions( $table, $op['names'], $op['ranges'] ) ) {

                $done++;

            } else {

                $this->fail( sprintf(
                    '%s: FAILED to reshape %s..%s; see the database error above.',
                    $table, $op['start'], $op['less_than']
                ) );
            }
        }

        if ( ! $plan['fits'] ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s: still %d partitions against a budget of %d. Lower OWA_PARTITION_DETAIL_MONTHS '
              . 'to reduce it further; no more can be merged while the last %d months are kept '
              . 'at full granularity.',
                $table, $plan['projected'], $budget['limit'], $this->detailMonths()
            ) );
        }

        return $done;
    }

    /** Is the driver able to partition at all? Report once, clearly. */
    /**
     * Today, as yyyymmdd.
     *
     * A seam, so the carve-and-merge arithmetic can be exercised at a month
     * boundary, in a leap February, and after a run has been missed -- none of
     * which can be reached by waiting.
     *
     * @return string
     */
    protected function today() {

        return date( 'Ymd' );
    }

    /**
     * Whether this table is the reporting cube.
     *
     * Only the cube has a front tier. Raw is never rebuilt and its retention is
     * a DROP PARTITION, so it would pay the merge for nothing, and v1's fact
     * tables keep the two tiers they have. Carving every fact table would spend
     * the open-file budget to no purpose.
     *
     * @param string $table
     * @return bool
     */
    protected function isCube( $table ) {

        return $table === \OWA\Core\CoreAPI::entityFactory( 'base.event' )->getTableName();
    }

    /**
     * How long a day stays daily before its month may merge back.
     *
     * This IS the late-arrival window: the point at which a day's partition
     * stops being cheap to rebuild is the point at which an event arriving for
     * it stops being folded in by an ordinary run. 3.1 has it open pending a
     * measurement of client-side lateness, so the default is a guess with the
     * right shape rather than a number anyone has earned.
     *
     * @return int
     */
    protected function windowDays() {

        $window = (int) \OWA\Core\CoreAPI::getSetting( 'base', 'cube_rebuild_window_days' );

        return $window > 0 ? $window : 7;
    }

    /**
     * Merge each month's daily partitions back once the window has passed it.
     *
     * A month cannot merge the moment it ends: its last days are still inside
     * the window, and merging would make settling them cost a month. So the
     * trigger is the window no longer reaching the month's final day, which is
     * why two months are daily for the first few days of each month.
     *
     * @param string $table
     * @param array  $spans
     * @param int    $window
     * @param bool   $dry_run
     * @return bool whether anything was merged
     */
    protected function mergeExpiredCubeDays( $table, $dry_run ) {

        if ( ! $this->isCube( $table ) ) {

            return false;
        }

        $window = $this->windowDays();
        $spans  = \OWA\Core\CoreAPI::dbSingleton()->getPartitionSpans( $table );

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $cutoff  = date( 'Ymd', strtotime( $this->today() . ' -' . (int) $window . ' days' ) );
        $months  = $this->dailyByMonth( $spans );
        $touched = false;

        foreach ( $months as $month => $group ) {

            $month_end = date( 'Ymd', strtotime( $month . '01 +1 month -1 day' ) );

            // Still inside the window, or it is this month.
            if ( $month_end >= $cutoff ) {

                continue;
            }

            if ( count( $group['names'] ) < 2 ) {

                continue;
            }

            $touched = true;

            \OWA\Core\CoreAPI::notice( sprintf(
                '  merge %d daily partitions of %s back into one (window passed %s).',
                count( $group['names'] ), $month, $month_end ) );

            if ( $dry_run ) {

                continue;
            }

            if ( ! $db->mergePartitions( $table, $group['names'], $group['start'], $group['less_than'] ) ) {

                $this->fail( sprintf( '%s: merging %s failed.', $table, $month ) );

                return $touched;
            }
        }

        return $touched;
    }

    /**
     * Carve an empty monthly partition into days.
     *
     * Only empty ones, and only the current month or later: reorganizing a
     * partition that holds rows rewrites all of them, which is the cost this
     * scheme exists to avoid paying repeatedly.
     *
     * @param string $table
     * @param array  $spans
     * @param bool   $dry_run
     * @return bool whether anything was carved
     */
    protected function carveCubeMonths( $table, $budget, $dry_run ) {

        if ( ! $this->isCube( $table ) ) {

            return false;
        }

        $spans = \OWA\Core\CoreAPI::dbSingleton()->getPartitionSpans( $table );

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $touched = false;

        /*
         * ONLY THE CURRENT MONTH AND THE NEXT ONE.
         *
         * extendPartitions() fills PARTITION_MONTHS_AHEAD (12) of lead, and
         * carving all of it would put ~390 daily partitions on the table --
         * spending the whole budget on empty future months, which is exactly
         * what 2.8 says the two-stage scheme exists to avoid. The daily lead
         * only has to cover the rebuild window plus margin, and one month of
         * margin is ample for a job that runs at least monthly.
         */
        /*
         * Projected cumulatively: each carve adds to what the last one left, so
         * checking a single carve against the budget in isolation would wave
         * through a run that breaches it three carves later.
         *
         * Not directly covered by a test -- it only diverges from the naive
         * version within a carve or two of the limit, and carveCandidates()
         * caps a run at two. It is defence for the day the horizon widens.
         */
        $projected = count( $spans );

        foreach ( $this->carveCandidates( $spans ) as $span ) {

            $contents = $db->getPartitionContents( $table, $span['name'] );
            $rows     = $contents ? (int) $contents['rows'] : 0;

            if ( $rows > 0 && ! $this->getParam( 'force' ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '  %s holds %s rows, so carving it would rewrite them. Left alone; the '
                  . 'next month is carved while still empty. Use force=1 to carve it anyway.',
                    $span['name'], number_format( $rows ) ) );

                continue;
            }

            $ranges = \OWA\Core\Db::makePartitionRangesForSpan(
                $span['start'], $span['less_than'], 'daily' );

            if ( ! $ranges ) {

                continue;
            }

            $projected = $projected - 1 + count( $ranges );

            if ( ! $this->withinPartitionBudget( $table, $projected, $budget ) ) {

                return $touched;
            }

            $touched = true;

            \OWA\Core\CoreAPI::notice( sprintf(
                '  carve %s (%s to %s, %s rows) into %d daily partitions.',
                $span['name'], $span['start'], $span['less_than'],
                number_format( $rows ), count( $ranges ) ) );

            if ( $dry_run ) {

                continue;
            }

            if ( ! $db->reorganizePartitions( $table, array( $span['name'] ), $ranges ) ) {

                $this->fail( sprintf( '%s: carving %s failed.', $table, $span['name'] ) );

                return $touched;
            }
        }

        return $touched;
    }

    /**
     * The spans a run may carve, by shape alone.
     *
     * ONLY THE CURRENT MONTH AND THE NEXT ONE. extendPartitions() fills
     * PARTITION_MONTHS_AHEAD (12) of lead, and carving all of it would put
     * ~390 daily partitions on the table -- spending the whole budget on empty
     * future months, which is exactly what the two-stage scheme exists to
     * avoid. The daily lead only has to cover the rebuild window plus margin,
     * and one month of margin is ample for a job that runs at least monthly.
     *
     * THE HORIZON ALSO KEEPS partition-rotate CORRECT, which is less obvious
     * and more dangerous. Granularity is never stored: inferPartitionGranularity()
     * reads the LAST span's month and matches its day boundaries against
     * PARTITION_CUTS. The furthest-future partitions are monthly lead precisely
     * because this horizon stops short of them, so the cube infers `monthly`
     * and partition-rotate extends its lead a month at a time. Carve to the end
     * of the lead and the inference flips to `daily` -- at which point
     * partition-rotate would extend twelve months of lead AT DAILY, ~365
     * partitions, with no warning. CubeRotateTest pins that the last span is
     * never a candidate.
     *
     * @param array $spans
     * @return array
     */
    protected function carveCandidates( array $spans ) {

        $this_month = substr( $this->today(), 0, 6 ) . '01';
        $horizon    = date( 'Ymd', strtotime( $this_month . ' +1 month' ) );

        $candidates = array();

        foreach ( $spans as $span ) {

            // Already fine-grained, older than this month, or beyond the margin.
            if ( $this->spanDays( $span ) <= 1
              || $span['less_than'] <= $this_month
              || $span['start'] > $horizon ) {

                continue;
            }

            $candidates[] = $span;
        }

        return $candidates;
    }

    /**
     * The daily partitions, grouped by the calendar month they belong to.
     *
     * @param array $spans
     * @return array yyyymm => ['names','start','less_than']
     */
    protected function dailyByMonth( array $spans ) {

        $months = array();

        foreach ( $spans as $span ) {

            if ( $this->spanDays( $span ) > 1 ) {

                continue;
            }

            $month = substr( $span['start'], 0, 6 );

            if ( ! isset( $months[ $month ] ) ) {

                $months[ $month ] = array(
                    'names'     => array(),
                    'start'     => date( 'Ymd', strtotime( $month . '01' ) ),
                    'less_than' => date( 'Ymd', strtotime( $month . '01 +1 month' ) ),
                );
            }

            $months[ $month ]['names'][] = $span['name'];
        }

        return $months;
    }

    /**
     * How many days a span covers.
     *
     * Read from the bounds rather than from the name: a monthly partition and
     * the first daily one of the same month are both called p2026MM01, so the
     * name cannot tell them apart.
     *
     * @param array $span
     * @return int
     */
    protected function spanDays( array $span ) {

        $start = strtotime( $span['start'] );
        $end   = strtotime( $span['less_than'] );

        if ( ! $start || ! $end || $end <= $start ) {

            return 0;
        }

        return (int) round( ( $end - $start ) / 86400 );
    }

    protected function assertPartitioningSupported() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $db->supportsPartitioning() ) {

            \OWA\Core\CoreAPI::notice( 'This database driver does not support partitioning; nothing to do.' );

            return false;
        }

        return true;
    }
}
