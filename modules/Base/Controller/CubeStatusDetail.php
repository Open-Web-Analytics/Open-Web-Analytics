<?php
namespace OWA\Module\Base\Controller;

/**
 * One Property's reporting cube: every check, the last fortnight day by day,
 * and how far its partitions reach. Reached from the Reporting Cubes list.
 */
class CubeStatusDetail extends \OWA\Core\AdminController {

    // edit_modules, and access to the Property the request names.
    use \OWA\Core\PropertyScopedAccess;

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    public function validate() {

        $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'required',
            [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'Choose a Property.' ) ] );
    }

    public function action() {

        $property_id = (string) $this->getParam( 'propertyId' );

        // Empty when no Property was named or it has no id shape; the template says so.
        $this->set( 'cube', ( ctype_digit( $property_id )
            ? \OWA\Module\Base\Classes\Cube\Status::detail( $property_id ) : null ) ?: [] );

        CubeStatus::hierarchy( $this );

        $this->setSubview( 'base.cubeStatusDetail', [
            'template' => 'cube_status_detail.php', 'vars' => [ 'cube' ] ] );
    }

    public function errorAction() {

        $this->setRedirectAction( 'base.cubeStatus' );
    }
}
