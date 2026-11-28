<?php
namespace OWA\Module\Base\Controller;
/**
 * Change the partition granularity of the fact tables.
 *
 * Granularity is named for how many parts a month is divided into -- monthly,
 * half-month, quarter-month -- never for a length, because months are 28
 * to 31 days and any name carrying a day count would be wrong in some month.
 *
 * Cut points are days of the month, so every boundary falls on a month start
 * and a change rewrites one month at a time rather than the whole table. It can
 * be run against part of the range, which is how an installation ends up coarse
 * for old data and fine for recent:
 *
 *   cmd=partition-reorganize granularity=half-month
 *   cmd=partition-reorganize granularity=quarter-month from=20260801 to=20260901
 *   cmd=partition-reorganize granularity=monthly --dry-run
 *
 * The reporting cube is partly exempt. The front of its lead is daily and
 * cmd=partition-rotate maintains it, so this leaves that part alone and changes
 * the granularity of the rest; `daily` is refused for it outright. An explicit
 * from=/to= covering the front is still honoured.
 */
class PartitionReorganizeCli extends PartitionsCli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Changes the partition granularity of the event tables, one month at a time. On a reporting cube the daily front is left to partition-rotate.',
            'arguments'   => [
                'granularity' => [
                    'type'        => 'select',
                    'options'     => [ 'monthly', 'half-month', 'quarter-month', 'daily' ],
                    'required'    => true,
                    'description' => 'The new partition size. daily is refused for a cube.',
                ],
                'from' => [
                    'type'        => 'date',
                    'description' => 'Reorganize from this date.',
                ],
                'to' => [
                    'type'        => 'date',
                    'not_before'  => 'from',
                    'description' => 'Reorganize up to this date.',
                ],
                'table' => [
                    'type'        => 'string',
                    'value'       => '<name>',
                    'description' => 'Reorganize only this table.',
                ],
                '--force' => [
                    'type'        => 'flag',
                    'description' => 'Proceed when the result needs more partitions than the ceiling allows.',
                ],
                '--dry-run' => [
                    'type'        => 'flag',
                    'description' => 'Report the plan, and change nothing.',
                ],
            ],
        ];
    }

    public function action() {

        if ( ! $this->assertPartitioningSupported() ) {

            return;
        }

        $db          = $this->db();
        $granularity = (string) $this->param( 'granularity' );
        $dry_run     = $this->param( 'dry-run' );
        $from        = $this->param( 'from' );
        $to          = $this->param( 'to' );

        $tables = $this->factTables( $this->param( 'table' ) );
        $budget = $this->factTableBudget();

        $touched = 0;

        foreach ( $tables as $table ) {

            if ( ! $db->isPartitioned( $table ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s is not partitioned. Skipping.', $table
                ) );

                continue;
            }

            /*
             * DAILY IS NOT AN OPERATOR'S CHOICE FOR THE CUBE.
             *
             * Granularity is never stored: inferPartitionGranularity() reads
             * the LAST span. Take the cube wholly daily and the next
             * partition-rotate infers `daily` and extends twelve months of lead
             * at daily -- some 365 partitions, with no warning. The front two
             * months are daily already and partition-rotate owns them
             * (Db::CUBE_DAILY_MONTHS); what this command sets is the
             * granularity of the rest.
             */
            if ( $granularity === 'daily' && $this->dailyLeadMonths( $table ) ) {

                $this->refuse( sprintf(
                    '%s: refusing daily. The front of its lead is daily already and '
                  . 'cmd=partition-rotate maintains it; taking the whole table daily would make '
                  . 'the next rotate extend a year of lead at daily. Choose quarter-month, '
                  . 'half-month or monthly for the rest of the lead.', $table ) );

                continue;
            }

            $touched++;

            /*
             * The cube's daily front is left alone unless a range asks for it.
             *
             * Those are ordinary one-day partitions, so every filter in
             * repartitionTable() admits them and a change of granularity would
             * merge them away -- rewriting live rows, which the next rotate
             * would rewrite again putting them back. An explicit from=/to= is
             * still honoured, for an operator who means it.
             */
            $protect = ( $from === null && $to === null && $this->dailyLeadMonths( $table ) )
                ? $this->dailyCoverage( $db->getPartitionSpans( $table ) )
                : null;

            if ( $protect ) {

                $protect = [ 'start' => $protect['start'], 'less_than' => $protect['end'] ];

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: leaving %s to %s daily -- that is the front of its lead, which '
                  . 'cmd=partition-rotate maintains.', $table, $protect['start'], $protect['less_than'] ) );
            }

            // Plan first so the count can be judged before anything is rewritten.
            $plan = $db->repartitionTable( $table, $granularity, true, $from, $to, $protect );

            // A finer granularity multiplies the detail window, which can put the
            // table over its budget. Coarsening old history is what makes room:
            // the tail exists to be traded for detail where detail is wanted.
            // Without this, moving to quarter-month on a long-history table would
            // simply be refused, with nothing the operator could do about it.
            if ( $plan['planned'] > $budget['limit'] && ! $this->param( 'force' ) ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: %s needs %d partitions, over the ceiling of %d. Merging old periods to '
                  . 'make room.', $table, $granularity, $plan['planned'], $budget['limit']
                ) );

                // Compaction has to be told what the table is about to need, not
                // what it currently holds: a finer granularity is not applied
                // yet, so measured against today's count the table may already
                // fit and nothing would be merged. Reserve the difference.
                $extra = $plan['planned'] - count( $db->getPartitionSpans( $table ) );

                $room = [
                    'limit'  => max( 1, $budget['limit'] - max( 0, $extra ) ),
                    'reason' => sprintf(
                        '%s, less %d reserved for the finer granularity', $budget['reason'], max( 0, $extra )
                    ),
                ];

                $this->compactTable( $table, $room, $dry_run );

                // Re-plan against what the table now looks like. A dry run's
                // compaction changed nothing, so it plans against the
                // partitions that compaction would leave.
                $spans = $dry_run
                    ? \OWA\Core\Db::applyReshapes( $db->getPartitionSpans( $table ),
                        $db->planPartitionCompaction( $table, $room['limit'], $this->detailMonths() )['operations'] )
                    : null;

                $plan = $db->repartitionTable( $table, $granularity, true, $from, $to, $protect, $spans );
            }

            if ( ! $this->withinPartitionBudget( $table, $plan['planned'], $budget ) ) {

                continue;
            }

            $result = $dry_run ? $plan : $db->repartitionTable( $table, $granularity, false, $from, $to, $protect );

            if ( ! $result['changed'] && ! $result['failed'] ) {

                \OWA\Core\CoreAPI::notice( sprintf(
                    '%s: already %s (%d period%s checked).', $table, $granularity, $result['skipped'],
                    $result['skipped'] === 1 ? '' : 's'
                ) );

                continue;
            }

            foreach ( $result['changed'] as $change ) {

                \OWA\Core\CoreAPI::notice( sprintf( '%s: %s%s', $table, $dry_run ? 'would rewrite ' : 'rewrote ', $change ) );
            }

            \OWA\Core\CoreAPI::notice( sprintf(
                '%s: %s %d period%s, %d already correct.',
                $table, $dry_run ? 'would rewrite' : 'rewrote', count( $result['changed'] ),
                \OWA\Core\Lib::plural( count( $result['changed'] ), '', 's' ), $result['skipped']
            ) );

            if ( $result['failed'] ) {

                $this->fail( sprintf(
                    '%s: FAILED on %s. The table is still valid -- the periods that did change are done.',
                    $table, implode( '; ', $result['failed'] )
                ) );
            }
        }

        // Same reasoning as partition-rotate: having skipped everything is not
        // success, and a caller -- including the scheduler, since any command
        // can be scheduled -- must be able to tell the difference.
        if ( ! $touched ) {

            $this->refuse( 'Nothing to reorganize: no fact table is partitioned.' );
        }
    }
}
