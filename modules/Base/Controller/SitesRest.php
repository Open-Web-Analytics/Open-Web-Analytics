<?php
namespace OWA\Module\Base\Controller;

/**
 * Tracked Sites REST Controller
 * 
 * A GET REST method for obtaiing the list of tracked web sites
 *
 */
class SitesRest extends \OWA\Core\ReportController {

    /** The roster is of every Profile the caller may see, not of one Property. */
    protected $rest_needs_property = false;

    public function __construct( $params ) {

        parent::__construct( $params );

        /*
         * view_site_list is the capability meaning "any signed-in user" -- the
         * endpoint answers with only the Profiles the caller may see, so the
         * gate is on being someone rather than on a particular site.
         */
        $this->setRequiredCapability( 'view_site_list' );
    }

    /*
     * Its own action rather than one inherited from a screen controller, so
     * the endpoint does not depend on a screen.
     */
    public function action() {

        $this->set( 'tracked_sites', $this->getSitesAllowedForCurrentUser() );
    }

    

    public function success() {
        
        http_response_code(200);
        
        $this->setView( 'base.sitesRest' );
    }
}
