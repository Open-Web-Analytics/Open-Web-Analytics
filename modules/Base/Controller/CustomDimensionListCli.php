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
 * The room is worth printing beside the list. The cap is the number an operator
 * plans against; the row budget underneath it is a backstop, and is shown so
 * that a refusal from it is never a surprise.
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

            $this->reportRoom( $table, count( $rows ) );
        }

        \OWA\Core\CoreAPI::notice( sprintf( '%d dimension(s) across %d cube(s).',
            $total, count( $cubes ) ) );
    }

    /**
     * How many of the twenty are used, and how the row is doing underneath.
     *
     * The cap is what an operator plans against; the bytes are the backstop and
     * are worth a line only so that a refusal from them is not a surprise. They
     * were the headline until a flat cap replaced them, and the reason they
     * could not stay one is that the number moves whenever a release adds a
     * column to the cube.
     *
     * @param string $table
     * @param int    $used
     * @return void
     */
    protected function reportRoom( $table, $used ) {

        $cap = \OWA\Module\Base\Classes\Cube\Dimensions::MAX_PER_PROPERTY;

        \OWA\Core\CoreAPI::notice( sprintf( '  %d of %d used, %d left.',
            $used, $cap, max( 0, $cap - $used ) ) );

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $bytes = $db->tableRowBytes( $table );

        if ( $bytes === null ) {

            return;
        }

        $spare  = \OWA\Module\Base\Classes\Cube\Dimensions::MAX_ROW_BYTES - $bytes;
        $maxlen = (int) $db->tableCharsetMaxLen( $table );
        $each   = \OWA\Module\Base\Classes\Cube\Dimensions::definitionRowBytes(
            sprintf( 'VARCHAR(%d)', \OWA\Module\Base\Classes\Cube\Dimensions::DIMENSION_LENGTH ),
            $maxlen );

        $affordable = intdiv( $spare, max( 1, $each ) );

        \OWA\Core\CoreAPI::notice( sprintf(
            '  row: %s of %s bytes left, which is %d more at VARCHAR(%d)%s',
            number_format( $spare ),
            number_format( \OWA\Module\Base\Classes\Cube\Dimensions::MAX_ROW_BYTES ),
            $affordable, \OWA\Module\Base\Classes\Cube\Dimensions::DIMENSION_LENGTH,
            $affordable >= ( $cap - $used )
                ? ' -- so the cap is what binds, not the row.'
                : ' -- LESS THAN THE CAP ALLOWS, so the row is what binds here.' ) );
    }
}

?>

?>
