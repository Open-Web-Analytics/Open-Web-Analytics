<?php

namespace OWA\Module\Base\Controller;

/**
 * Rename the Organization.
 *
 * There is exactly one, created as "My Organization" by the installer or by
 * Update021 -- a name nobody chose. It is the top of the hierarchy and the
 * thing every Property hangs from, so leaving it unnameable meant the one tier
 * a user cannot edit is the one describing them.
 *
 * Only the name. Nothing else about an Organization is auto-generated, and
 * multi-Organization support does not exist yet -- when it does, this becomes a
 * roster like the Property one rather than a single form.
 */
class OrganizationEdit extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );

        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'name', trim( (string) $this->getParam( 'name' ) ), 'required',
            array( 'errorMsg' => 'An Organization needs a name.' ) );
    }

    function action() {

        $sm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'siteManager' );

        $organization = \OWA\Core\CoreAPI::entityFactory( 'base.organization' );
        $organization->load( $sm->ensureOrganization() );

        $organization->set( 'name', trim( (string) $this->getParam( 'name' ) ) );
        $organization->update();

        $this->setRedirectAction( 'base.organizationProfile' );
        $this->set( 'status_code', 3201 );
    }

    function errorAction() {

        $this->setRedirectAction( 'base.organizationProfile' );
        $this->set( 'error_msg', implode( ' ', (array) $this->getValidationErrorMsgs() ) );
    }
}

?>
