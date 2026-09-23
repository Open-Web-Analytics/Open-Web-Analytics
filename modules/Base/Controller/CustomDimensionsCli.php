<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * What the three custom-dimension commands have in common.
 *
 * Registering changes a Property's cube -- it issues DDL against the table
 * every report reads -- so all three want the same capability as the partition
 * commands rather than a reporting one.
 */
abstract class CustomDimensionsCli extends \OWA\Core\Controller\Cli {

    function __construct( $params ) {

        // Rewrites a cube, as the partition commands do.
        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    /**
     * The Property this command was given, or '' having already refused.
     *
     * @return string
     */
    protected function property() {

        $id = trim( (string) $this->getParam( 'property' ) );

        if ( ! ctype_digit( $id ) || $id === '0' ) {

            $this->refuse( 'property=<id> is required. cmd=custom-dimension-list with no '
                         . 'property lists every Property that has one.' );

            return '';
        }

        return $id;
    }

    /**
     * One registration, as a line.
     *
     * @param array $row
     * @return string
     */
    protected function describe( array $row ) {

        $columns = array_keys(
            \OWA\Module\Base\Classes\Cube\Dimensions::columnsOf( $row ) );

        $state = isset( $row['state'] ) ? (string) $row['state'] : '';

        return sprintf( '  %-24s %-7s %-8s %-8s -> %s%s',
            $row['dimension_key'],
            $row['scope'],
            $row['data_type'] === 'string'
                ? 'str(' . (int) $row['max_length'] . ')' : $row['data_type'],
            $state,
            implode( ', ', $columns ),
            empty( $row['state_message'] ) ? '' : '  [' . $row['state_message'] . ']' );
    }
}

?>
