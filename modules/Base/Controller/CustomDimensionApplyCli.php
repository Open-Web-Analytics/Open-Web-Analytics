<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Put the registered columns on the cubes that are missing them.
 *
 *   cmd=custom-dimension-apply                 every cube with something pending
 *   cmd=custom-dimension-apply property=<id>   just that one
 *
 * WHY THIS IS A COMMAND AND NOT PART OF REGISTERING. Adding the column is a
 * full table rebuild: 4.4 seconds on an empty 73-partition cube, 11.3 at 1.5M
 * rows, longer on a real one. PHP's web limit is 30 seconds, Varnish's is 60
 * and the load balancer's is 65 -- and a request killed at any of those does
 * NOT stop the ALTER. Measured: the client dies, MySQL finishes anyway. A
 * screen that did this inline would show a timeout, add the column regardless,
 * and invite a retry that pays a second rebuild before failing on a duplicate
 * column. So registering writes a row and this puts the column on.
 *
 * REGISTERED AS `apply-custom-dimensions`, every fifteen minutes and spread.
 * It costs one indexed read per cube when there is nothing to do, which is
 * almost always -- and what it buys is that a dimension registered from a
 * screen appears in minutes rather than at the next daily build. The build
 * reconciles too, so nothing depends on this job existing; it only makes the
 * wait short.
 *
 * It takes the CUBE'S OWN LOCK, the one a build takes, because that is the
 * thing an ALTER contends for: a build derives its staging table from the live
 * cube's DDL and then swaps, and an ALTER landing between the two makes
 * EXCHANGE PARTITION refuse the pair.
 */
class CustomDimensionApplyCli extends CustomDimensionsCli {

    /**
     * How long the lock outlives proof of life.
     *
     * One ALTER, not a run of them: the loop takes and releases per cube. Sized
     * well past the worst measured rebuild rather than tightly, because the
     * cost of a lease that is too short is two ALTERs on one table at once.
     */
    const APPLY_LEASE = 900;

    function action() {

        $only = trim( (string) $this->getParam( 'property' ) );

        if ( $only !== '' && ! ctype_digit( $only ) ) {

            return $this->refuse( 'property=<id> takes a Property id.' );
        }

        $existing = \OWA\Module\Base\Classes\Cube\Cubes::existing();

        if ( $only !== '' ) {

            $cubes = isset( $existing[ $only ] ) ? array( $only => $existing[ $only ] ) : array();

            if ( ! $cubes ) {

                return $this->refuse( sprintf(
                    'Property %s has no cube, so it has no columns to change.', $only ) );
            }

        } else {

            /*
             * Only the Properties with something pending, found in one indexed
             * read whatever the installation's size. Reconciling every cube on
             * every run would be a hundred and fifty pairs of queries every
             * fifteen minutes to discover there is nothing to do.
             *
             * A named Property is reconciled fully instead -- including drops,
             * which leave no pending row to find -- because naming one is
             * someone asking for it to be brought into line now.
             */
            $cubes = array();

            foreach ( \OWA\Module\Base\Classes\Cube\Dimensions::propertiesWithPendingWork()
                      as $property_id ) {

                if ( isset( $existing[ $property_id ] ) ) {

                    $cubes[ $property_id ] = $existing[ $property_id ];
                }
            }
        }

        $changed = 0;
        $failed  = 0;

        foreach ( $cubes as $property_id => $table ) {

            $outcome = $this->applyTo( $property_id, $table );

            $changed += $outcome['changed'];
            $failed  += $outcome['failed'];
        }

        if ( ! $changed && ! $failed ) {

            return \OWA\Core\CoreAPI::notice( $cubes
                ? sprintf( 'Every cube already matches its registry (%d checked).', count( $cubes ) )
                : 'Nothing is waiting for a column.' );
        }

        \OWA\Core\CoreAPI::notice( sprintf( '%d cube(s) altered.', $changed ) );

        if ( $failed ) {

            return $this->fail( sprintf(
                '%d cube(s) could not be brought up to date. Those dimensions stay '
              . 'unfilled; nothing else is affected.', $failed ) );
        }
    }

    /**
     * @param string $property_id
     * @param string $table
     * @return array ['changed' => int, 'failed' => int]
     */
    protected function applyTo( $property_id, $table ) {

        $lock = new \OWA\Module\Base\Classes\JobLease( 'cube-build:' . $table );

        if ( ! $lock->acquire( self::APPLY_LEASE ) ) {

            /*
             * A build has it. That build reconciles before it builds, so the
             * work happens anyway -- skipping is not deferring it, it is
             * noticing somebody else is already doing it.
             */
            \OWA\Core\CoreAPI::notice( sprintf(
                '%s is busy; its build will apply these.', $table ) );

            return array( 'changed' => 0, 'failed' => 0 );
        }

        try {

            $result = \OWA\Module\Base\Classes\Cube\Dimensions::reconcile( $property_id );

        } finally {

            $lock->release();
        }

        foreach ( $result['skipped'] as $column => $why ) {

            \OWA\Core\CoreAPI::error( sprintf(
                '%s: %s could not be added. %s', $table, $column, $why ) );
        }

        if ( $result['added'] || $result['dropped'] ) {

            \OWA\Core\CoreAPI::notice( sprintf( '%s: %s%s%s.',
                $table,
                $result['added'] ? 'added ' . implode( ', ', $result['added'] ) : '',
                $result['added'] && $result['dropped'] ? '; ' : '',
                $result['dropped'] ? 'dropped ' . implode( ', ', $result['dropped'] ) : '' ) );
        }

        return array(
            'changed' => $result['changed'] ? 1 : 0,
            'failed'  => ( ! $result['ok'] || $result['skipped'] ) ? 1 : 0,
        );
    }
}

?>
