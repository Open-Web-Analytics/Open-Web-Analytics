<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * One Property's reporting cube: every check, the last fortnight day by day,
 * and how far its partitions reach. Reached from the Reporting Cubes list.
 */
class CubeStatusDetail extends \OWA\Core\AdminController {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    function validate() {

        $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'required',
            array( 'errorMsg' => 'Say which Property\'s cube to show.' ) );
    }

    function action() {

        $property_id = (string) $this->getParam( 'propertyId' );

        $this->set( 'cube', ctype_digit( $property_id )
            ? \OWA\Module\Base\Classes\Cube\Status::detail( $property_id ) : null );

        CubeStatus::hierarchy( $this );

        $this->setSubview( 'base.cubeStatusDetail' );
    }

    function errorAction() {

        $this->setRedirectAction( 'base.cubeStatus' );
    }
}

?>
