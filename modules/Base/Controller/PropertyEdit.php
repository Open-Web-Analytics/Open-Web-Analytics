<?php

namespace OWA\Module\Base\Controller;

/**
 * Rename a Property.
 *
 * The migration names Properties for you -- from the site's domain, or from
 * whichever site it happened to see first -- so without this the names it chose
 * are permanent. That is the whole reason this screen exists.
 *
 * It deliberately does NOT touch the Profiles beneath it. A Profile's name is a
 * published API field (/v1/sites emits it, and the WordPress plugin labels its
 * picker with it), so renaming a Property must not cascade into one.
 */
class PropertyEdit extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->setRequiredCapability( 'edit_sites' );
        $this->setNonceRequired();
    }

    public function validate() {

        /*
         * The name is required rather than allowed to be blank. A Property with
         * no name has nothing to head its group in the site selector, which
         * falls back to the domain and reads as though the Property had been
         * deleted.
         */
        $this->addValidation( 'name', trim( (string) $this->getParam( 'name' ) ), 'required',
            array( 'errorMsg' => 'A Property needs a name -- it is what the site selector groups by.' ) );

        /*
         * Only checked when editing. An absent id means create, so requiring
         * the row to exist would make adding a Property impossible.
         */
        if ( $this->getParam( 'propertyId' ) ) {

            $this->addValidation( 'propertyId', $this->getParam( 'propertyId' ), 'entityExists',
                array(
                    'entity'   => 'base.property',
                    'column'   => 'id',
                    'errorMsg' => 'That Property no longer exists.',
                ) );
        }
    }

    function action() {

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        $name        = trim( (string) $this->getParam( 'name' ) );
        $domain      = trim( (string) $this->getParam( 'domain' ) );
        $description = (string) $this->getParam( 'description' );

        $propertyId = $this->getParam( 'propertyId' );

        if ( $propertyId ) {

            $property->load( $propertyId );
            $property->set( 'name', $name );
            $property->set( 'domain', $domain );
            $property->set( 'description', $description );
            $property->update();

        } else {

            /*
             * A new Property joins the Organization every other one belongs to.
             * ensureOrganization() creates it on first need, so this works on
             * an install that has somehow never had one.
             */
            $sm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'siteManager' );

            $propertyId = $property->generateId( 'property:' . $name . ':' . uniqid( '', true ) );

            $property->set( 'id', $propertyId );
            $property->set( 'organization_id', $sm->ensureOrganization() );
            $property->set( 'name', $name );
            $property->set( 'domain', $domain );
            $property->set( 'description', $description );
            $property->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $property->create();
        }

        $this->set( 'propertyId', $propertyId );
        $this->setRedirectAction( 'base.propertyProfile' );
        $this->set( 'status_code', 3201 );
    }

    function errorAction() {

        $this->setRedirectAction( 'base.propertyProfile' );
        $this->set( 'error_msg', implode( ' ', (array) $this->getValidationErrorMsgs() ) );
    }
}

?>
