<?php
namespace OWA\Module\Base\Controller;

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

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return [
            'description' => 'Lists registered custom dimensions and how many more each Property has room for.',
            'arguments'   => [
                'property' => [
                    'type'        => 'property',
                    'description' => 'List only this Property\'s. Defaults to every Property that has one.',
                ],
            ],
        ];
    }

    public function action() {

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $only  = (string) $this->param( 'property' );
        $cubes = \OWA\Module\Base\Classes\Cube\Cubes::existing();

        if ( $only !== '' ) {

            $cubes = isset( $cubes[ $only ] )
                ? [ $only => $cubes[ $only ] ]
                : [ $only => \OWA\Module\Base\Classes\Cube\Cubes::tableFor( $only ) ];
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

            $this->reportRoom( $property_id, count( $rows ) );
        }

        \OWA\Core\CoreAPI::notice( sprintf( '%d dimension%s across %d cube%s.',
            $total, $total === 1 ? '' : 's', count( $cubes ), \OWA\Core\Lib::plural( count( $cubes ), '', 's' ) ) );
    }

    /**
     * How many of the cap are used.
     *
     * THE NUMBER IS MEASURED, NOT THE CONSTANT. Twenty is an outer cap; what
     * this server will actually take may be less, and on MySQL 8.0 it is --
     * 19 on a cube of today's shape, against at least 20 on 8.4 (see
     * Cube\Dimensions::MAX_PER_PROPERTY). Printing the cap when
     * the server allows fewer would promise room that a registration then
     * refuses.
     *
     * @param int|string $property_id
     * @param int        $used
     * @return void
     */
    protected function reportRoom( $property_id, $used ) {

        $capacity = \OWA\Module\Base\Classes\Cube\Dimensions::capacityFor( $property_id );
        $cap      = \OWA\Module\Base\Classes\Cube\Dimensions::MAX_PER_PROPERTY;

        \OWA\Core\CoreAPI::notice( sprintf( '  %d of %d used, %d left.%s',
            $used, $capacity, max( 0, $capacity - $used ),
            $capacity < $cap
                ? sprintf( ' (%d rather than the usual %d: this server will not take more '
                         . 'columns on a row of this cube\'s shape.)', $capacity, $cap )
                : '' ) );
    }
}
