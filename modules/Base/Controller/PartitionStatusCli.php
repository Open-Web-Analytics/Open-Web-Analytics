<?php
namespace OWA\Module\Base\Controller;
//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
/**
 * Report the partitioning state of the fact tables.
 *
 * The other three commands change things; this one only looks. It answers the
 * questions an operator has before deciding whether to run any of them: what is
 * partitioned, how much time the partitions cover, at what resolution, how much
 * of the open-file budget is spent, whether anything has collected in the
 * catch-all, and how long the lead lasts before a rotate is due.
 *
 *   php cli.php cmd=partition-status
 *   php cli.php cmd=partition-status table=owa_session
 *
 * Nothing here writes, so it is safe to run on a busy installation. It reads
 * metadata for the layout, and touches table data only to count the catch-all --
 * one partition, through an index that is local to it.
 *
 * The report is built as lines and then emitted, rather than emitted as it is
 * decided, so that what it says about a given layout can be asserted directly.
 */
class PartitionStatusCli extends PartitionsCli {

    function action() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        if ( ! $this->assertPartitioningSupported() ) {

            return;
        }

        $tables = $this->factTables( $this->getParam( 'table' ) ?: null );

        if ( ! $tables ) {

            return;
        }

        $budget  = $this->factTableBudget();
        $layouts = array();

        foreach ( $tables as $table ) {

            $layout = $db->describePartitionLayout( $table );

            // Read the catch-all only where there is one. This is the single
            // place the command touches table data.
            $layout['contents'] = $layout['catch_all']
                ? $db->getPartitionContents( $table, $layout['catch_all'] )
                : null;

            $layouts[ $table ] = $layout;
        }

        $lines = array( sprintf(
            'Partition status, %s. Budget: %d partitions per table (%s).',
            date( 'Y-m-d' ), $budget['limit'], $budget['reason']
        ) );

        foreach ( $layouts as $table => $layout ) {

            $lines[] = '';
            $lines   = array_merge( $lines, $this->describeTable( $table, $layout, $budget ) );
        }

        $lines[] = '';
        $lines   = array_merge( $lines, $this->summarise( $layouts, $budget ) );

        $this->write( $lines );
    }

    /**
     * Put the report on standard output.
     *
     * The other commands report through CoreAPI::notice(), and should: what they
     * did is a record worth keeping, and the console handler puts it on stdout as
     * well as in the log. A report is not that. It is terminal output, it is read
     * once, and routing it through the logger would stamp every line with a
     * timestamp, a pid and a level, interleave it with debug output on an
     * installation running the development handler, and write fifty lines into
     * the error log on each run -- which, scheduled beside partition-rotate,
     * would make the status of the partitions the noisiest thing in it.
     *
     * Refusals still go through notice(), because those are events.
     *
     * @param string[] $lines
     * @return void
     */
    protected function write( $lines ) {

        // The base controller exits unless this is a CLI request, so STDOUT is
        // the CLI SAPI's own constant. The fallback is for a test harness that
        // has not defined it.
        $out = defined( 'STDOUT' ) ? STDOUT : fopen( 'php://output', 'w' );

        foreach ( $lines as $line ) {

            fwrite( $out, $line . PHP_EOL );
        }
    }

    /**
     * One table's block of the report.
     *
     * @param string $table
     * @param array  $layout  from describePartitionLayout(), plus 'contents'
     * @param array  $budget  from partitionLimit()
     * @return string[]
     */
    protected function describeTable( $table, $layout, $budget ) {

        if ( ! $layout['partitioned'] ) {

            return array( sprintf(
                '%s: not partitioned. Run cmd=partition-init to convert it.', $table
            ) );
        }

        $lines = array( sprintf(
            '%s: %d partitions (%d bounded + catch-all), %d%% of budget.',
            $table,
            $layout['total'],
            $layout['spans'],
            $budget['limit'] > 0 ? (int) round( $layout['total'] / $budget['limit'] * 100 ) : 0
        ) );

        if ( $layout['covers'] ) {

            $lines[] = sprintf(
                '  covers        %s to %s',
                $this->readable( $layout['covers']['start'] ),
                $this->readable( $layout['covers']['end'] )
            );
        }

        // What the next rotate will cut new periods at. Deliberately separate
        // from the tiers below, which are what is already on disk: rotate infers
        // this from the most recent month, so a coarsened tail does not change
        // it, and a table can be monthly at the head while holding years per
        // partition at the other end.
        $lines[] = '  granularity   ' . (
            $layout['granularity']
                ? $layout['granularity'] . ' -- what new periods will be cut at'
                : 'not recognised; new periods will be cut monthly. '
                . 'cmd=partition-reorganize sets it deliberately.'
        );

        $lines = array_merge( $lines, $this->describeTiers( $layout ) );
        $lines = array_merge( $lines, $this->describeCatchAll( $layout ) );
        $lines = array_merge( $lines, $this->describeLead( $layout ) );

        return $lines;
    }

    /**
     * The layout as tiers.
     *
     * A rotated table has a coarse tail and a fine head, so one granularity
     * cannot describe it: it would either overstate the resolution of the old
     * data or understate the new. Each run of partitions covering the same
     * length of time is reported with the span it covers.
     *
     * @param array $layout
     * @return string[]
     */
    protected function describeTiers( $layout ) {

        $lines = array();

        foreach ( $layout['tiers'] as $tier ) {

            $lines[] = sprintf(
                '  %-13s %d partition%s, %s to %s',
                $tier['period'],
                $tier['count'],
                $tier['count'] === 1 ? '' : 's',
                $this->readable( $tier['start'] ),
                $this->readable( $tier['end'] )
            );
        }

        if ( count( $layout['tiers'] ) > 1 ) {

            $lines[] = sprintf(
                '                Older periods have been merged to fit the budget; the most recent '
              . '%d months keep full granularity (OWA_PARTITION_DETAIL_MONTHS).',
                $this->detailMonths()
            );
        }

        return $lines;
    }

    /**
     * What has collected in the catch-all, if anything.
     *
     * An empty catch-all is the normal, healthy state, and saying so explicitly
     * is worth a line: its presence alone alarms people. Rows in it are not lost
     * and not unreadable -- it is simply the one partition that has no upper
     * bound, so queries cannot prune past it and retention cannot drop it. The
     * next rotate reorganises them into dated partitions.
     *
     * @param array $layout
     * @return string[]
     */
    protected function describeCatchAll( $layout ) {

        if ( ! $layout['catch_all'] ) {

            // Only reachable if something outside these commands removed it.
            // Worth flagging loudly, because inserts past the last boundary do
            // not fall back anywhere -- MySQL rejects the row.
            return array(
                '  catch-all     NONE -- rows dated past the last boundary will be REJECTED, not '
              . 'stored. Run cmd=partition-rotate to restore it.'
            );
        }

        if ( $layout['contents'] === null ) {

            return array( sprintf(
                '  catch-all     %s (contents could not be read)', $layout['catch_all']
            ) );
        }

        if ( ! $layout['contents']['rows'] ) {

            return array( sprintf(
                '  catch-all     %s, empty -- every row is in a dated partition.',
                $layout['catch_all']
            ) );
        }

        return array(
            sprintf(
                '  catch-all     %s, %s rows dated %s to %s',
                $layout['catch_all'],
                number_format( $layout['contents']['rows'] ),
                $this->readable( $layout['contents']['min'] ),
                $this->readable( $layout['contents']['max'] )
            ),
            '                Those rows are queryable and are not at risk. They are outside the '
          . 'dated partitions, so reports covering them cannot prune and retention cannot drop '
          . 'them. The next cmd=partition-rotate moves them into dated partitions.',
        );
    }

    /**
     * How much lead is left, and therefore when a rotate is due.
     *
     * @param array $layout
     * @return string[]
     */
    protected function describeLead( $layout ) {

        if ( $layout['ahead'] === null ) {

            return array( '  lead          unknown -- no bounded partitions to read a boundary from.' );
        }

        if ( $layout['ahead'] <= 0 ) {

            return array( sprintf(
                '  lead          NONE -- boundaries stop at %s, %d day%s ago. Everything logged '
              . 'since then is going to the catch-all. Rotate now.',
                $this->readable( $layout['through'] ),
                abs( $layout['ahead'] ),
                abs( $layout['ahead'] ) === 1 ? '' : 's'
            ) );
        }

        return array( sprintf(
            '  lead          %d partition%s ahead, through %s -- %d day%s from today.',
            $layout['lead'],
            $layout['lead'] === 1 ? '' : 's',
            $this->readable( $layout['through'] ),
            $layout['ahead'],
            $layout['ahead'] === 1 ? '' : 's'
        ) );
    }

    /**
     * The lines an operator acts on.
     *
     * @param array $layouts  keyed by table
     * @param array $budget
     * @return string[]
     */
    protected function summarise( $layouts, $budget ) {

        $unpartitioned = array();
        $overdue       = array();
        $total         = 0;
        $soonest       = null;
        $start         = null;
        $end           = null;

        foreach ( $layouts as $table => $layout ) {

            if ( ! $layout['partitioned'] ) {

                $unpartitioned[] = $table;

                continue;
            }

            $total += $layout['total'];

            if ( $layout['covers'] ) {

                $start = ( $start === null || $layout['covers']['start'] < $start )
                       ? $layout['covers']['start'] : $start;

                $end   = ( $end === null || $layout['covers']['end'] > $end )
                       ? $layout['covers']['end'] : $end;
            }

            if ( $layout['ahead'] === null ) {

                continue;
            }

            if ( $layout['ahead'] <= 0 ) {

                $overdue[] = $table;

            } elseif ( $soonest === null || $layout['ahead'] < $soonest ) {

                $soonest = $layout['ahead'];
            }
        }

        $lines = array( sprintf(
            '%d of %d fact tables partitioned, %d partitions in total%s.',
            count( $layouts ) - count( $unpartitioned ),
            count( $layouts ),
            $total,
            ( $start && $end )
                ? sprintf( ', covering %s to %s', $this->readable( $start ), $this->readable( $end ) )
                : ''
        ) );

        if ( $unpartitioned ) {

            $lines[] = sprintf(
                'Not partitioned: %s. cmd=partition-init converts %s.',
                implode( ', ', $unpartitioned ),
                count( $unpartitioned ) === 1 ? 'it' : 'them'
            );
        }

        // An expired lead outranks everything else: it is the only state where
        // data is actively accumulating somewhere it should not be.
        if ( $overdue ) {

            $lines[] = sprintf(
                'ACTION: %s %s out of lead and %s logging into the catch-all. Run '
              . 'cmd=partition-rotate.',
                implode( ', ', $overdue ),
                count( $overdue ) === 1 ? 'has run' : 'have run',
                count( $overdue ) === 1 ? 'is' : 'are'
            );

            return $lines;
        }

        if ( $soonest !== null ) {

            $lines[] = sprintf(
                'The shortest lead runs out on %s, %d day%s from now -- that is the deadline for '
              . 'the next cmd=partition-rotate. Running it monthly from cron keeps the lead topped '
              . 'up and the catch-all empty.',
                $this->readable( date( 'Ymd', strtotime( '+' . $soonest . ' days' ) ) ),
                $soonest,
                $soonest === 1 ? '' : 's'
            );
        }

        return $lines;
    }

    /**
     * A yyyymmdd as a date a person reads.
     *
     * @param string|null $yyyymmdd
     * @return string
     */
    protected function readable( $yyyymmdd ) {

        $d = \DateTimeImmutable::createFromFormat( 'Ymd|', (string) $yyyymmdd );

        return $d ? $d->format( 'Y-m-d' ) : (string) $yyyymmdd;
    }
}
