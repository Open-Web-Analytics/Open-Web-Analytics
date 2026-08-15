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
