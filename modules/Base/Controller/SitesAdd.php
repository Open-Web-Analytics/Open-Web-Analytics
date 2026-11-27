<?php
namespace OWA\Module\Base\Controller;

/**
 * Add Site Controller
 */

class SitesAdd extends \OWA\Core\AdminController {

    // edit_sites on the Property the Profile is added to, not on the
    // request's siteId; create_properties when a new one is made for it.
    // The rules and the creation are AddsProfile's.
    use \OWA\Core\PropertyScopedAccess, \OWA\Module\Base\Classes\AddsProfile {
        \OWA\Module\Base\Classes\AddsProfile::createsProperty insteadof \OWA\Core\PropertyScopedAccess;
        \OWA\Module\Base\Classes\AddsProfile::requestPropertyId insteadof \OWA\Core\PropertyScopedAccess;
    }

    public function __construct( $params ) {
        
        $this->setRequiredCapability('edit_sites');
        
        parent::__construct( $params );
    }
    
    public function init() {
        
        $this->setNonceRequired();
      
    }

    public function action() {

        $this->addProfile();
    }

    public function success() {

        /*
         * To the new Profile's Tracking Tag, not to reporting.
         *
         * The tag is what a new Profile is for, and it has no data to report
         * yet. action() has put the created row in 'site'.
         *
         * Read from $this->data, NOT $this->get(): get() reads the request,
         * where 'site' is empty.
         */
        $site = (array) ( $this->data['site'] ?? [] );

        if ( ! empty( $site['site_id'] ) ) {

            $this->set( 'siteId', $site['site_id'] );
            $this->setRedirectAction( 'base.sitesInvocation' );
            $this->setStatusCode( 3202 );

        } else {

            $this->setRedirectAction( 'base.sitesProfile' );
            $this->set( 'error_code', 3203 );
        }
    }

    public function errorAction() {
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->settingsChrome( [ 'siteId' => $owa_site_id ], '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $owa_site_id ) );
        $this->setSubview('base.sitesProfile');
        /*
         * 3002 -- "the form had errors" -- not 3311, which is the CLI-updates
         * message. Controller::doAction() sets validation_errors before calling
         * errorAction() and msgs.php shows error_msg only when there are none, so
         * a wrong code here shows only once that branch changes.
         */
        $this->set('error_code', 3002);
        $this->set('site', $this->params);
    }

}
