<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * What is registered, and what room is left to register more.
 *
 *   cmd=custom-dimension-list                 every Property that has one
 *   cmd=custom-dimension-list property=<id>   just that Property's
 *
 * The count is printed beside the list, because how many are left is the one
 * thing a person needs before deciding whether to register another.
 */
class CustomDimensionListCli extends CustomDimensionsCli {

    function action() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $only  = trim( (string) $this->getParam( 'property' ) );
        $cubes = \OWA\Module\Base\Classes\Cube\Cubes::existing();

        if ( $only !== '' ) {

            if ( ! ctype_digit( $only ) ) {

                return $this->refuse( 'property=<id> takes a Property id.' );
            }

            $cubes = isset( $cubes[ $only ] )
                ? array( $only => $cubes[ $only ] )
                : array( $only => \OWA\Module\Base\Classes\Cube\Cubes::tableFor( $only ) );
        }

        if ( ! $cubes ) {

            return \OWA\Core\CoreAPI::notice(
                'No Property has a cube yet. One is created when a Property first collects '
              . 'something, and there is nothing to register a dimension against until then.' );
        }

        $total = 0;

        foreach ( $cubes as $property_id => $table ) {

            $rows = \OWA\Module\Base\Classes\Cube\Dimensions::forProperty( $property_id );

            $total += count( $rows );

            \OWA\Core\CoreAPI::notice( sprintf( '%s: %d registered.%s',
                $table, count( $rows ),
                $db->tableExists( $table ) ? '' : ' The cube does not exist.' ) );

            foreach ( $rows as $row ) {

                \OWA\Core\CoreAPI::notice( $this->describe( $row ) );
            }

            $this->reportRoom( count( $rows ) );
        }

        \OWA\Core\CoreAPI::notice( sprintf( '%d dimension(s) across %d cube(s).',
            $total, count( $cubes ) ) );
    }

    /**
     * How many of the cap are used.
     *
     * There was a second line here reporting how much of MySQL's row-size limit
     * the cube had left. It went because it was answering a question nobody has
     * to ask: twenty dimensions need 4,020 bytes of the 12,509 a cube leaves, so
     * the cap is reached long before the row is, and reporting both invited the
     * reader to work out which one was binding when the answer is always the
     * cap.
     *
     * @param int $used
     * @return void
     */
    protected function reportRoom( $used ) {

        $cap = \OWA\Module\Base\Classes\Cube\Dimensions::MAX_PER_PROPERTY;

        \OWA\Core\CoreAPI::notice( sprintf( '  %d of %d used, %d left.',
            $used, $cap, max( 0, $cap - $used ) ) );
    }
}

?>

?>
