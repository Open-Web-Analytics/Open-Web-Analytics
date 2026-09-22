<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Promote a collected key to a column of a Property's cube.
 *
 *   cmd=custom-dimension-register property=<id> key=plan scope=user
 *   cmd=custom-dimension-register property=<id> key=tier scope=event type=integer
 *   cmd=custom-dimension-register property=<id> key=plan,tier,region scope=event
 *   cmd=custom-dimension-register property=<id> key=sku scope=event length=36
 *   cmd=custom-dimension-register property=<id> key=plan scope=user --defer
 *   cmd=custom-dimension-register property=<id> key=plan scope=user --dry-run
 *
 * REGISTERING RECORDS IT; THE COLUMN ARRIVES SEPARATELY. Adding it is a full
 * table rebuild -- 4.4 seconds on an empty 73-partition cube, 11.3 at 1.5M rows
 * -- so it happens under the cube's own lock, either at the next build or at
 * the next cmd=custom-dimension-apply, whichever comes first. From a CLI there
 * is no request to time out, so this applies it inline unless told not to.
 *
 *   --defer   record it and leave the ALTER to the build or the apply job
 *
 * SEVERAL AT ONCE COSTS ONE REBUILD. The ALTER rewrites the table whatever it
 * carries -- ten columns in one measured 13,664ms against 13,057ms for one, and
 * a combined add-and-drop 4,193ms against 4,361ms for a single add -- so
 * `key=a,b,c` is not a convenience, it is the difference between one rebuild
 * and three. It is also why deferring is not a penalty: everything registered
 * between two applies goes on in one statement.
 *
 * NOTHING IS BACKFILLED. The column is NULL for rows that already exist and the
 * next build fills it going forward. To reach back, run cmd=cube-rebuild over
 * the range wanted -- which is possible at all because `params` is kept on
 * every raw row, and is the one thing this does that GA cannot: a GA custom
 * dimension is not retroactive, so everything collected before it was
 * registered is permanently unreportable.
 */
class CustomDimensionRegisterCli extends CustomDimensionsCli {

    function action() {

        $property_id = $this->property();

        if ( $property_id === '' ) {

            return;
        }

        $keys = array_filter( array_map( 'trim',
            explode( ',', (string) $this->getParam( 'key' ) ) ), 'strlen' );

        if ( ! $keys ) {

            return $this->refuse( 'key=<name> is required, or key=a,b,c to register '
                                . 'several in one rebuild.' );
        }

        $requests = array();

        foreach ( $keys as $key ) {

            $requests[] = array(
                'key'    => $key,
                'scope'  => (string) $this->getParam( 'scope' ),
                'type'   => (string) $this->getParam( 'type' ),
                'length' => (string) $this->getParam( 'length' ),
                // One label for several keys would be wrong, so a label is only
                // taken when one key was named.
                'label'  => count( $keys ) === 1 ? (string) $this->getParam( 'label' ) : '',
            );
        }

        if ( $this->getParam( 'dry-run' ) ) {

            $this->reportPlan( $property_id, $requests );

            return;
        }

        $result = \OWA\Module\Base\Classes\Cube\Dimensions::register( $property_id, $requests );

        if ( ! $result['ok'] ) {

            return $this->fail( $result['error'] );
        }

        foreach ( $result['registered'] as $row ) {

            \OWA\Core\CoreAPI::notice( $this->describe( $row ) );
        }

        $table = \OWA\Module\Base\Classes\Cube\Cubes::tableFor( $property_id );

        \OWA\Core\CoreAPI::notice( sprintf(
            'Recorded %d dimension(s) for %s; the row will then spend %s of %s bytes.',
            count( $result['registered'] ), $table,
            number_format( $result['bytes'] ),
            number_format( \OWA\Module\Base\Classes\Cube\Dimensions::MAX_ROW_BYTES ) ) );

        if ( $this->getParam( 'defer' ) ) {

            return \OWA\Core\CoreAPI::notice( sprintf(
                'Deferred: the column goes on at the next build of %s, or sooner with '
              . 'cmd=custom-dimension-apply property=%s.', $table, $property_id ) );
        }

        /*
         * Applied inline because this is a CLI: there is no request to time
         * out, and an operator who typed the command wants the column, not a
         * promise of one. It is the same reconcile the build runs, under the
         * same lock.
         */
        $this->apply( $property_id );

        \OWA\Core\CoreAPI::notice( sprintf(
            'Nothing is backfilled -- run cmd=cube-rebuild property=%s from=<date> to fill '
          . 'it over history.', $property_id ) );
    }

    /**
     * Put the columns on now, by running the apply command's own action.
     *
     * Delegated rather than reimplemented, so the locking and the reporting are
     * the same however the reconcile was reached.
     *
     * @param string $property_id
     * @return void
     */
    protected function apply( $property_id ) {

        $class = new \ReflectionClass( CustomDimensionApplyCli::class );
        $cli   = $class->newInstanceWithoutConstructor();

        $params = $class->getProperty( 'params' );
        $params->setAccessible( true );
        $params->setValue( $cli, array( 'property' => $property_id ) );

        $cli->action();
    }

    /**
     * What it would do, without the rebuild.
     *
     * @param string $property_id
     * @param array  $requests
     * @return void
     */
    protected function reportPlan( $property_id, array $requests ) {

        $table = \OWA\Module\Base\Classes\Cube\Cubes::tableFor( $property_id );
        $names = array();

        foreach ( $requests as $request ) {

            $names[] = \OWA\Module\Base\Classes\Cube\Dimensions::columnFor( $request['key'] )
                ?: ( $request['key'] . ' (not a usable name)' );
        }

        \OWA\Core\CoreAPI::notice( sprintf(
            'Would record %s against %s, and add them in one ALTER ... ALGORITHM=INPLACE, '
          . 'which rebuilds the table once. Dry run; nothing was changed.',
            implode( ', ', $names ), $table ) );
    }
}

?>
