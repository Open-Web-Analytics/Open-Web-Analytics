<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Stop reporting on a key, and take its column back.
 *
 *   cmd=custom-dimension-deregister property=<id> key=plan
 *   cmd=custom-dimension-deregister property=<id> key=plan --defer
 *
 * THE COLUMN GOES WITH IT, and so do the values in it. They are derived -- a
 * build put them there from `params`, which this does not touch -- so
 * registering again and rebuilding the range brings them back. That is the
 * whole reason dropping the column is acceptable rather than reckless: the
 * record of what was observed is somewhere else.
 *
 * The registration goes now and the column goes at the next reconcile, for the
 * same reason it arrived at one: the DROP is a full table rebuild, and pinned
 * to ALGORITHM=INPLACE because an instant DROP leaves the same row-format
 * metadata an instant ADD does, which makes EXCHANGE PARTITION refuse every
 * later build with error 1731. In between the column is INERT -- a build fills
 * the columns the registry names, and this one is no longer named -- so there
 * is no window in which anything is wrong, only one in which something is
 * untidy. From a CLI it is applied inline, since there is no request to time
 * out; --defer leaves it to the build.
 */
class CustomDimensionDeregisterCli extends CustomDimensionsCli {

    function action() {

        $property_id = $this->property();

        if ( $property_id === '' ) {

            return;
        }

        $key = trim( (string) $this->getParam( 'key' ) );

        if ( $key === '' ) {

            return $this->refuse( 'key=<name> is required.' );
        }

        $result = \OWA\Module\Base\Classes\Cube\Dimensions::deregister( $property_id, $key );

        if ( ! $result['ok'] ) {

            return $this->fail( $result['error'] );
        }

        $table = \OWA\Module\Base\Classes\Cube\Cubes::tableFor( $property_id );

        \OWA\Core\CoreAPI::notice( sprintf(
            '%s de-registered. %s will be dropped from %s; the values go with the column, '
          . 'and a later registration plus cmd=cube-rebuild reproduces them from '
          . 'owa_event_raw.',
            $key, implode( ', ', $result['columns'] ), $table ) );

        if ( $this->getParam( 'defer' ) ) {

            return \OWA\Core\CoreAPI::notice( sprintf(
                'Deferred: the column goes at the next build of %s.', $table ) );
        }

        $class = new \ReflectionClass( CustomDimensionApplyCli::class );
        $cli   = $class->newInstanceWithoutConstructor();

        $params = $class->getProperty( 'params' );
        $params->setAccessible( true );
        $params->setValue( $cli, array( 'property' => $property_id ) );

        $cli->action();
    }
}

?>
