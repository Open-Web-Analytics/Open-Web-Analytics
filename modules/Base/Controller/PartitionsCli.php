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
 * The fact tables are the partitioned ones, and they come from the module's
 * entity registry rather than a list here, so the set stays correct as entities
 * are added.
 */
abstract class PartitionsCli extends \OWA\Core\Controller\Cli {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    /**
     * The fact tables, by name.
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

            if ( ! $entity instanceof \OWA\Core\Entity\FactTable ) {

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
                '"%s" is not a fact table. Partitioning applies to: %s.',
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

        $spare = \OWA\Core\CoreAPI::dbSingleton()->getPartitionBudget();

        if ( $spare === null ) {

            return array(
                'limit'  => \OWA\Core\Db::PARTITION_COUNT_LIMIT,
                'reason' => 'default limit; this server does not report its open-file budget',
            );
        }

        // A floor, so that a server reporting almost no headroom still permits
        // a couple of years of monthly rather than refusing everything.
        $limit = max( 24, intdiv( $spare, 2 * max( 1, $table_count ) ) );

        return array(
            'limit'  => $limit,
            'reason' => sprintf(
                '%d spare open-file slots on this server, half of them shared across %d table(s)',
                $spare, $table_count
            ),
        );
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
          . 'everything on the instance. Narrow it with from/to, choose a coarser granularity, or '
          . 're-run with force=1 if you have done the arithmetic.',
            $table, $planned, $budget['limit'], $budget['reason']
        ) );

        return false;
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
