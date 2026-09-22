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
 * The room is worth printing beside the list, because it is the part nobody
 * can work out for themselves: a cube spends most of a row's 65,535 bytes on
 * its own columns, and what remains buys very different numbers of dimensions
 * depending on how wide each one is.
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

            $this->reportRoom( $table );
        }

        \OWA\Core\CoreAPI::notice( sprintf( '%d dimension(s) across %d cube(s).',
            $total, count( $cubes ) ) );
    }

    /**
     * How much of the row is left, in the units an operator chooses in.
     *
     * @param string $table
     * @return void
     */
    protected function reportRoom( $table ) {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $bytes = $db->tableRowBytes( $table );

        if ( $bytes === null ) {

            return;
        }

        $spare  = \OWA\Module\Base\Classes\Cube\Dimensions::MAX_ROW_BYTES - $bytes;
        $maxlen = (int) $db->tableCharsetMaxLen( $table );

        $fits = function ( $definition ) use ( $spare, $maxlen ) {

            return intdiv( $spare, \OWA\Module\Base\Classes\Cube\Dimensions::definitionRowBytes(
                $definition, $maxlen ) );
        };

        \OWA\Core\CoreAPI::notice( sprintf(
            '  room: %s of %s row bytes left -- %d more VARCHAR(255), %d more VARCHAR(64), '
          . 'or %d more VARCHAR(36).',
            number_format( $spare ),
            number_format( \OWA\Module\Base\Classes\Cube\Dimensions::MAX_ROW_BYTES ),
            $fits( 'VARCHAR(255)' ), $fits( 'VARCHAR(64)' ), $fits( 'VARCHAR(36)' ) ) );
    }
}

?>
