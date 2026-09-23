<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Stop reporting on a key, and give its column back.
 *
 * A real delete of the registration, and eventually of the column and the
 * values in it -- which is acceptable because they are DERIVED. A build put
 * them there by reading owa_event_raw, which this does not touch, so
 * registering the key again and rebuilding the range reproduces them exactly.
 * What is destroyed is a copy, and the original is somewhere else.
 *
 * The row goes now and the column goes at the next reconcile, for the same
 * reason it arrived at one: the DROP is a full table rebuild and cannot happen
 * inside the request. In between the column is INERT -- a build fills the
 * columns the registry names, and this one is no longer named -- so there is no
 * window in which anything is wrong, only one in which something is untidy.
 */
class CustomDimensionDelete extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'required' );
        $this->addValidation( 'dimensionKey', $this->getParam( 'dimensionKey' ), 'required' );
    }

    function action() {

        $result = \OWA\Module\Base\Classes\Cube\Dimensions::deregister(
            (string) $this->getParam( 'propertyId' ),
            trim( (string) $this->getParam( 'dimensionKey' ) ) );

        $this->set( 'siteId', $this->getParam( 'siteId' ) );
        $this->setRedirectAction( 'base.customDimensions' );

        if ( ! $result['ok'] ) {

            \OWA\Core\CoreAPI::notice( sprintf(
                'Removing %s was refused: %s',
                $this->getParam( 'dimensionKey' ), $result['error'] ) );

            $this->set( 'status_code', 3200 );

            return;
        }

        $this->set( 'status_code', 3211 );
    }
}

?>
