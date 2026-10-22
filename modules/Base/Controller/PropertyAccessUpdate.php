<?php
namespace OWA\Module\Base\Controller;

/**
 * Saves the Property Access form.
 *
 * The submission is a delta, not a replacement: the form posts the ids it
 * rendered alongside the ids that were checked, and only users appearing in
 * the rendered list can be affected. That is what makes an empty submission
 * mean "everyone shown was unchecked" rather than "revoke every user", which
 * is what it meant when the form was a multi-select.
 *
 * The delta is applied to every Profile of the Property (PropertyGrants).
 */
class PropertyAccessUpdate extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->setRequiredCapability( 'edit_sites' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'required', array( 'stopOnError' => true ) );

        $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'entityExists', array(
            'entity'   => 'base.property',
            'column'   => 'id',
            'errorMsg' => $this->getMsg( 3218 ),
        ) );
    }

    function action() {

        $rendered = $this->getParam( 'rendered_users' );
        $checked  = $this->getParam( 'allowed_users' );

        \OWA\Module\Base\Classes\PropertyGrants::apply(
            (string) $this->getParam( 'propertyId' ),
            is_array( $rendered ) ? $rendered : array(),
            is_array( $checked )  ? $checked  : array()
        );

        $this->set( 'propertyId', $this->getParam( 'propertyId' ) );
        $this->setStatusCode( 3217 );
        $this->setRedirectAction( 'base.propertyAccess' );
    }

    function errorAction() {

        $this->set( 'propertyId', $this->getParam( 'propertyId' ) );
        $this->setRedirectAction( 'base.propertyAccess' );
        $this->set( 'error_code', 3218 );
    }
}

?>
