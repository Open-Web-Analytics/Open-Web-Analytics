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

        $db = $this->db();

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
     * The database.
     *
     * The second seam the cube's lead maintenance needs. Its merge and carve read the live
     * partition list and then issue DDL against it, so without this a test can
     * only reach the arithmetic -- and the loop that turns a decision into an
     * ALTER goes uncovered.
     *
     * @return \OWA\Core\Db
     */
    protected function db() {

        return \OWA\Core\CoreAPI::dbSingleton();
    }

    /**
     * How much of this table's lead has to be daily, as the ENTITY declares it.
     *
     * Asked here rather than decided here. The three things that shape a
     * table's partitions -- Db::createTable(), partition-rotate's lead
     * maintenance, and partition-reorganize -- all read the same declaration,
     * so a table cannot be created in one shape and maintained in another.
     * Two of the three were once written as if every fact table were alike and
     * both were wrong the same way.
     *
     * Table NAME in, because that is what the partition commands work in; the
     * entity is looked up rather than assumed.
     *
     * @param string $table
     * @return int  months, or 0 for a table of one granularity throughout
     */
    protected function dailyLeadMonths( $table ) {

        $entity = $this->entityFor( $table );

        return ( $entity && method_exists( $entity, 'getDailyLeadMonths' ) )
            ? (int) $entity->getDailyLeadMonths() : 0;
    }

    /**
     * The entity behind a partitioned table's name.
     *
     * Same registry walk factTables() makes, so the two cannot disagree about
     * which entity owns a name.
     *
     * @param string $table
     * @return object|null
     */
    protected function entityFor( $table ) {

        $s  = \OWA\Core\CoreAPI::serviceSingleton();
        $ns = \OWA\Core\CoreAPI::getSetting( 'base', 'ns' );

        foreach ( $s->modules['base']->getEntities() as $name ) {

            if ( $ns . $name === $table ) {

                return \OWA\Core\CoreAPI::entityFactory( 'base.' . $name );
            }
        }

        return null;
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
     * How many of these spans are already daily.
     *
     * @param array $spans
     * @return int
     */
    protected function dailyCount( array $spans ) {

        $daily = 0;

        foreach ( $spans as $span ) {

            if ( $this->spanDays( $span ) === 1 ) {

                $daily++;
            }
        }

        return $daily;
    }

    /**
     * Merge each month's daily partitions back once the window has passed it.
     *
     * A month cannot merge the moment it ends: its last days are still inside
     * the window, and merging would make settling them cost a month. So the
     * trigger is the window no longer reaching the month's final day.
     *
     * This is the half that RELEASES partitions; carvePlan() is the half that
     * claims them, and it claims only out to cubeDailyBoundary(). A merge here
     * is what stops the daily part trailing backwards without limit while the
     * carve extends it forwards.
     *
     * @param string $table
     * @param bool   $dry_run
     * @return bool whether anything was merged
     */
    protected function mergeExpiredCubeDays( $table, $dry_run ) {

        if ( ! $this->dailyLeadMonths( $table ) ) {

            return false;
        }

        $db      = $this->db();
        $spans   = $db->getPartitionSpans( $table );
        $touched = false;

        $granularity = $db->inferPartitionGranularity( $table ) ?: 'monthly';

        foreach ( $this->mergeablePeriods( $spans, $granularity ) as $month => $group ) {

            $touched = true;

            \OWA\Core\CoreAPI::notice( sprintf(
                '  merge %d daily partitions from %s back into one (window passed %s).',
                count( $group['names'] ), $month, $group['period_end'] ) );

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
     * The periods whose daily partitions the window no longer reaches.
     *
     * A PERIOD OF THE TABLE'S OWN GRANULARITY, not a calendar month. If the
     * table's granularity is quarter-month then the cycle is quarter-monthly:
     * the window clears a quarter, that quarter merges back to one partition,
     * and the carve extends the daily part in the same run. Monthly is only the
     * default.
     *
     * The exact complement of carveCandidates(): merge once a period's last day
     * is older than the window, carve while it is not. Disjoint by
     * construction, so nothing is merged and re-carved on alternating runs --
     * which would rewrite the table forever for no change in shape.
     *
     * A period is merged only when its daily partitions tile it completely. A
     * partial period would merge into a span overlapping the days still
     * separate, which the server refuses.
     *
     * @param array  $spans
     * @param string $granularity
     * @return array  period start => ['names','start','less_than','period_end']
     */
    protected function mergeablePeriods( array $spans, $granularity = 'monthly' ) {

        $cutoff = date( 'Ymd', strtotime( $this->today() . ' -' . $this->windowDays() . ' days' ) );

        $mergeable = array();

        foreach ( $this->dailyByPeriod( $spans, $granularity ) as $start => $group ) {

            $period_end = date( 'Ymd', strtotime( $group['less_than'] . ' -1 day' ) );
            $whole      = $this->spanDays( $group );

            // Still reached by the window, nothing to merge, or only part of
            // the period is daily.
            if ( $period_end >= $cutoff
              || count( $group['names'] ) < 2
              || count( $group['names'] ) !== $whole ) {

                continue;
            }

            $group['period_end']  = $period_end;
            $mergeable[ $start ]  = $group;
        }

        return $mergeable;
    }

    /**
     * Extend the daily part of the lead, carving only empty partitions.
     *
     * Reorganizing a partition that holds rows rewrites all of them, which is
     * the cost this scheme exists to avoid paying repeatedly, so a candidate
     * holding rows is reported and left alone. force=1 overrides that.
     *
     * @param string $table
     * @param array  $budget   from partitionLimit()
     * @param bool   $dry_run
     * @return bool whether anything was carved
     */
    protected function carveCubeMonths( $table, $budget, $dry_run ) {

        if ( ! $this->dailyLeadMonths( $table ) ) {

            return false;
        }

        $db      = $this->db();
        $spans   = $db->getPartitionSpans( $table );
        $touched = false;

        /*
         * Projected cumulatively: each carve adds to what the last one left, so
         * checking a single carve against the budget in isolation would wave
         * through a run that breaches it three carves later.
         */
        $projected = count( $spans );

        foreach ( $this->carvePlan( $spans ) as $span ) {

            $contents = $db->getPartitionContents( $table, $span['name'] );
            $rows     = $contents ? (int) $contents['rows'] : 0;

            /*
             * A partition holding rows is normally left alone, because
             * reorganizing it rewrites every one of them.
             *
             * THE ONE THAT IS TAKING WRITES IS THE EXCEPTION. If today falls
             * inside a monthly partition then the daily part has fallen
             * behind -- an install upgrading into it, or a run missed long
             * enough for it to run out -- and every cube
             * rebuild until that month ends rewrites a month. Carving it costs
             * that rewrite ONCE and every rebuild afterwards is a day. Leaving
             * it costs the same rewrite on every run.
             *
             * Only that one. A past month holding rows is not being written to
             * and merges shortly anyway, so rewriting it buys nothing.
             */
            $current = $span['start'] <= $this->today() && $this->today() < $span['less_than'];

            if ( $rows > 0 && ! $current && ! $this->getParam( 'force' ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '  %s holds %s rows and is not the one taking writes, so carving it would '
                  . 'rewrite them for nothing. Left alone. Use force=1 to carve it anyway.',
                    $span['name'], number_format( $rows ) ) );

                continue;
            }

            if ( $rows > 0 && $current ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '  %s is monthly and taking writes, so every rebuild is rewriting %s rows. '
                  . 'Carving it once to get the daily part of the lead back on track.',
                    $span['name'], number_format( $rows ) ) );
            }

            $projected = $projected - 1 + count( $span['ranges'] );

            if ( ! $this->withinPartitionBudget( $table, $projected, $budget ) ) {

                /*
                 * ONE LEAD, ONE BUDGET -- and the daily part is the half that
                 * loses. extendTableLead() runs first and fills twelve months
                 * at the coarse granularity, which is a dozen partitions and
                 * always fits; the daily part is sixty and is what the budget
                 * refuses. So the table keeps a lead, at the wrong granularity
                 * for rebuilding, and the operator has to be told which half
                 * was dropped rather than left to infer it from a partition
                 * count.
                 */
                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: the lead is in place but its front is not daily, so every cube '
                  . 'rebuild rewrites a whole %s. Raise the partition budget, or accept '
                  . 'month-sized rebuilds.',
                    $table, $this->db()->inferPartitionGranularity( $table ) ?: 'period' ) );

                return $touched;
            }

            $touched = true;

            \OWA\Core\CoreAPI::notice( sprintf(
                '  carve %s (%s to %s, %s rows) into %d daily partitions%s.',
                $span['name'], $span['start'], $span['less_than'],
                number_format( $rows ), $span['days'],
                $span['days'] < $this->spanDays( $span )
                    ? sprintf( ', leaving %s to %s as one',
                        date( 'Ymd', strtotime( $span['start'] . ' +' . $span['days'] . ' days' ) ),
                        $span['less_than'] )
                    : '' ) );

            if ( $dry_run ) {

                continue;
            }

            if ( ! $db->reorganizePartitions( $table, array( $span['name'] ), $span['ranges'] ) ) {

                $this->fail( sprintf( '%s: carving %s failed.', $table, $span['name'] ) );

                return $touched;
            }
        }

        return $touched;
    }

    /**
     * The spans a run may carve, in time order, by shape alone.
     *
     * Shape only -- how MUCH of them to carve is carvePlan()'s decision, and
     * whether they hold rows is the caller's.
     *
     * NEVER THE LAST SPAN, which is less obvious than the rest and more
     * dangerous. Granularity is never stored: inferPartitionGranularity()
     * reads the LAST span's month and matches its day boundaries against
     * PARTITION_CUTS. The furthest-future partitions have to stay monthly lead
     * so the cube infers `monthly` and partition-rotate extends its lead a
     * month at a time. Let the carve reach the end of the lead and the
     * inference flips to `daily` -- at which point partition-rotate would
     * extend twelve months of lead AT DAILY, ~365 partitions, with no warning.
     *
     * @param array $spans
     * @return array
     */
    protected function carveCandidates( array $spans ) {

        /*
         * The window's reach is the lower bound, not the start of this month.
         *
         * A month that has ENDED can still be inside the rebuild window -- on
         * 5 December with a seven-day window, November is rebuilt until the
         * 7th -- and those rebuilds are month-sized until it is carved. Cutting
         * at the start of the current month would exclude it before anything
         * looked at what carving would actually cost, so an operator asking why
         * would be told "it is in the past" when the real answer is "it holds
         * rows, and this would rewrite them". force=1 has something to override
         * this way, and nothing the other.
         */
        $reach = date( 'Ymd', strtotime( $this->today() . ' -' . $this->windowDays() . ' days' ) );

        usort( $spans, function ( $a, $b ) { return strcmp( $a['start'], $b['start'] ); } );

        $last       = count( $spans ) - 1;
        $candidates = array();

        foreach ( $spans as $i => $span ) {

            // Already fine-grained, ended before the window reaches it, or the
            // one the granularity inference reads.
            if ( $this->spanDays( $span ) <= 1
              || $span['less_than'] <= $reach
              || $i === $last ) {

                continue;
            }

            $candidates[] = $span;
        }

        return $candidates;
    }

    /**
     * What to carve this run.
     *
     * Whole spans, and only while the daily part of the lead is shallower than
     * Db::CUBE_DAILY_MONTHS. That gate is what keeps the count flat: a month is
     * carved only once the month behind it has merged and given the partitions
     * back, so merge and carve happen in the same run and the daily part never
     * holds three months at once.
     *
     * Without it the carve runs ahead of the merge -- carving on the 1st while
     * the merge waits for the window to clear the previous month's last day,
     * about the 8th -- and it is three months wide for that week. The
     * open-file budget then has to be sized for a peak near 103 partitions to
     * hold what only ever needs about 60, and the difference is spent on empty
     * future days.
     *
     * Spans are carved WHOLE, so the daily part ends where the table's own
     * granularity begins. Nothing is part-carved and no remainder is left
     * behind for a later run to find.
     *
     * @param array $spans
     * @return array  each span plus 'ranges' (name => less_than) and 'days'
     */
    protected function carvePlan( array $spans ) {

        $candidates = $this->carveCandidates( $spans );

        if ( ! $candidates ) {

            return array();
        }

        $coverage = $this->dailyCoverage( $spans );

        // Nothing daily yet, so it starts where the first carve will.
        if ( ! $coverage ) {

            $coverage = array( 'start' => $candidates[0]['start'], 'end' => $candidates[0]['start'] );
        }

        $enough = date( 'Ymd', strtotime(
            $coverage['start'] . ' +' . \OWA\Core\Db::CUBE_DAILY_MONTHS . ' months' ) );

        $plan = array();

        foreach ( $candidates as $span ) {

            // yyyymmdd compares as a string in date order.
            if ( $coverage['end'] >= $enough ) {

                break;
            }

            $ranges = \OWA\Core\Db::makePartitionRangesForSpan(
                $span['start'], $span['less_than'], 'daily' );

            if ( ! $ranges ) {

                continue;
            }

            $span['ranges'] = $ranges;
            $span['days']   = count( $ranges );

            $plan[] = $span;

            $coverage['end'] = $span['less_than'];
        }

        return $plan;
    }

    /**
     * How far the daily part of the lead currently runs.
     *
     * @param array $spans
     * @return array|null  ['start','end'], or null when nothing is daily
     */
    protected function dailyCoverage( array $spans ) {

        $start = null;
        $end   = null;

        foreach ( $spans as $span ) {

            if ( $this->spanDays( $span ) !== 1 ) {

                continue;
            }

            if ( $start === null || $span['start'] < $start ) {

                $start = $span['start'];
            }

            if ( $end === null || $span['less_than'] > $end ) {

                $end = $span['less_than'];
            }
        }

        return $start === null ? null : array( 'start' => $start, 'end' => $end );
    }

    /**
     * The daily partitions, grouped by the calendar month they belong to.
     *
     * @param array  $spans
     * @param string $granularity  the one the table is otherwise on
     * @return array  period start (yyyymmdd) => ['names','start','less_than']
     */
    protected function dailyByPeriod( array $spans, $granularity = 'monthly' ) {

        $periods = array();

        foreach ( $spans as $span ) {

            if ( $this->spanDays( $span ) !== 1 ) {

                continue;
            }

            $period = $this->periodFor( $span['start'], $granularity );

            if ( ! $period ) {

                continue;
            }

            if ( ! isset( $periods[ $period['start'] ] ) ) {

                $period['names'] = array();

                $periods[ $period['start'] ] = $period;
            }

            $periods[ $period['start'] ]['names'][] = $span['name'];
        }

        ksort( $periods );

        return $periods;
    }

    /**
     * The period of a given granularity that a date falls in.
     *
     * Every granularity cuts on days of the month, so the periods of the month
     * holding the date are the candidates and the one containing it is the
     * answer. Month-aligned by construction, which is what makes a merged
     * partition tile exactly the span its daily partitions covered.
     *
     * @param string $date  yyyymmdd
     * @param string $granularity
     * @return array|null  ['start','less_than'], or null for an unknown granularity
     */
    protected function periodFor( $date, $granularity ) {

        $month = substr( $date, 0, 6 ) . '01';

        $ranges = \OWA\Core\Db::makePartitionRangesForSpan(
            $month, date( 'Ymd', strtotime( $month . ' +1 month' ) ), $granularity );

        foreach ( $ranges as $name => $less_than ) {

            $start = substr( $name, 1 );

            if ( $date >= $start && $date < $less_than ) {

                return array( 'start' => $start, 'less_than' => $less_than );
            }
        }

        return null;
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

    /** Is the driver able to partition at all? Report once, clearly. */
    protected function assertPartitioningSupported() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $db->supportsPartitioning() ) {

            \OWA\Core\CoreAPI::notice( 'This database driver does not support partitioning; nothing to do.' );

            return false;
        }

        return true;
    }
}
