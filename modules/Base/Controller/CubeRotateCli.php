<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Keep the cube's front tier daily, and merge it back when the window passes.
 *
 *   cmd=cube-rotate               merge what has expired, carve what is coming
 *   cmd=cube-rotate --dry-run     say what it would do
 *   cmd=cube-rotate force=1       carve the current month even though it has rows
 *
 * NOT REGISTERED AS A JOB, for the same reason cmd=cube-rebuild is not: the
 * cube is empty until a site turns v2 collection on, and a job reshaping an
 * unused table is the wrong default. Daily is the cadence it wants -- a run
 * with nothing to do is two catalogue queries -- and it must run at least
 * monthly or a month goes current before it has been carved. In owa-config.php:
 *
 *   define( 'OWA_SCHEDULED_JOBS', serialize( array(
 *       'rotate-cube' => array( 'command' => 'cube-rotate', 'schedule' => '@daily' ),
 *   ) ) );
 *
 * It is a separate job from partition-rotate deliberately. That one is shipped,
 * registered and load-bearing for every fact table; this reshapes one table and
 * should not be able to break it.
 *
 * A build rebuilds a WHOLE partition, so the partition scheme sets the unit of
 * work -- and the cost tracks the row count, mildly superlinearly: 13.1s at
 * 100k rows against 162s at 1M. On a monthly partition the last run of the
 * month rewrites a month, and at a quarter-hourly cadence that is 96 of them a
 * day. Daily at the front makes each run flat instead.
 *
 * THREE TIERS, AND ONLY THE MIDDLE ONE IS ANYONE'S CHOICE
 *   front   daily, always, fixed. Not a setting: the interval and the
 *           granularity are one decision, so a knob here would let an install
 *           pick a monthly front tier with a quarter-hourly run and get a stall
 *           that nothing reports.
 *   middle  as now -- inferred per table, moved only by partition-reorganize.
 *   back    as now -- year blocks, partition_max_years_per_block.
 *
 * ONLY THE CUBE. Raw is never rebuilt and its retention is a DROP PARTITION, so
 * it pays no merge and gains nothing from being finer at the front. v1's fact
 * tables keep the two tiers they have. Carving every fact table would spend the
 * open-file budget for nothing.
 *
 * MERGE BEFORE CARVE, in one run. Merging first frees a month of partitions
 * before the carve consumes one, so a run peaks near its starting count; a run
 * that dies between the two leaves the table below where it started rather than
 * over budget.
 *
 * CARVING IS ONLY FREE WHILE A MONTH IS EMPTY. Reorganizing a partition that
 * holds rows rewrites every one of them, so a month is carved BEFORE it goes
 * current, not once it is. That is also why the front tier can only be whole
 * middle-tier periods: the granularity of a period has to be decided before it
 * has data. A month with rows is left alone unless force=1 says otherwise.
 */
class CubeRotateCli extends PartitionsCli {

    function action() {

        if ( ! $this->assertPartitioningSupported() ) {

            return;
        }

        $db     = \OWA\Core\CoreAPI::dbSingleton();
        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.event' );
        $table  = $entity->getTableName();

        if ( ! $db->isPartitioned( $table ) ) {

            return $this->refuse( sprintf(
                '%s is not partitioned. Run cmd=partition-init first.', $table ) );
        }

        $dry_run = (bool) $this->getParam( 'dry-run' );
        $window  = $this->windowDays();
        $spans   = $db->getPartitionSpans( $table );

        \OWA\Core\CoreAPI::notice( sprintf(
            '%s: %d partitions, rebuild window %d day(s).%s',
            $table, count( $spans ), $window, $dry_run ? ' Dry run.' : '' ) );

        $merged = $this->mergeExpired( $table, $spans, $window, $dry_run );

        // Re-read: the merge changed the layout the carve has to plan against.
        if ( $merged && ! $dry_run ) {

            $spans = $db->getPartitionSpans( $table );
        }

        $carved = $this->carveUpcoming( $table, $spans, $dry_run );

        if ( ! $merged && ! $carved ) {

            \OWA\Core\CoreAPI::notice(
                'Nothing to do: the front tier is already daily and nothing has expired.' );
        }
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
    protected function mergeExpired( $table, array $spans, $window, $dry_run ) {

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $cutoff  = date( 'Ymd', strtotime( '-' . (int) $window . ' days' ) );
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
    protected function carveUpcoming( $table, array $spans, $dry_run ) {

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $budget  = $this->factTableBudget();
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
     * @param array $spans
     * @return array
     */
    protected function carveCandidates( array $spans ) {

        $this_month = date( 'Ym' ) . '01';
        $horizon    = date( 'Ymd', strtotime( 'first day of next month' ) );

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
}

?>
