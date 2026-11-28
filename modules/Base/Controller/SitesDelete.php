<?php
namespace OWA\Module\Base\Controller;

/**
 * Delete Site Controller
 */

class SitesDelete extends \OWA\Core\AdminController {

    // edit_sites, and access to the Property of the Profile the request names.
    use \OWA\Core\PropertyScopedAccess;

    public function __construct($params) {
        parent::__construct($params);
        $this->setRequiredCapability('edit_sites');
        $this->setNonceRequired();
    }

    /**
     * Archives the Profile rather than destroying it.
     *
     * Deleting the row would leave everything hanging off it behind -- the
     * access grants in owa_site_user, the scoped settings rows, and every fact
     * row ever collected under that site_id -- as orphans that a re-minted
     * identifier could inherit, and it would be unrecoverable.
     *
     * Archiving stamps a date and stops the Profile being listed, reported on,
     * or collected for. Everything it owns stays exactly where it is, which is
     * what makes a restore possible.
     */
    public function action() {

        $site = \OWA\Module\Base\Entity\Site::forSiteId( $this->getParam( 'siteId' ) );

        if ( $site->wasPersisted() ) {

            $site->set( 'archived_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $site->update();

            // An archived Profile stops collecting, so its tracking bundle is queued to be taken down.
            \OWA\Module\Base\Classes\TrackerBundle::remove( (string) $this->getParam( 'siteId' ) );
        }

        // Back to the roster it was reached from, which does not list it.
        if ( $site->get( 'property_id' ) ) {

            $this->set( 'propertyId', (string) $site->get( 'property_id' ) );
            $this->setRedirectAction( 'base.propertyProfiles' );

        } else {

            $this->setRedirectAction('base.reportingHome');
        }

        $this->setStatusCode( 3204 );
    }
}
