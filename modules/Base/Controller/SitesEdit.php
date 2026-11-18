<?php
namespace OWA\Module\Base\Controller;

/**
 * Edit User Controller
 */

class SitesEdit extends \OWA\Core\AdminController {

    public function __construct($params) {

        parent::__construct($params);

        $this->setRequiredCapability('edit_sites');
        $this->setNonceRequired();
    }

    public function validate()
    {
        // check that siteId is present
        $this->addValidation('siteId', $this->getParam('siteId'), 'required');

        // Check site exists
        $siteEntityConf = [
            'entity'    => 'base.site',
            'column'    => 'site_id',
            'errorMsg'  => $this->getMsg(3208)
        ];

        $this->addValidation('siteId', $this->getParam('siteId'), 'entityExists', $siteEntityConf);
    }

    public function action() {

        // This needs form validation in a bad way.

        if (! $this->getParam('siteId')) {
            throw new \InvalidArgumentException('No siteId passed on request');
        }
        $site = \OWA\Module\Base\Entity\Site::forSiteId( $this->getParam('siteId') );
        $site->set('name', $this->getParam( 'name' ) );
        $site->set('domain', \OWA\Module\Base\Classes\SiteManager::domainOnly( $this->getParam( 'domain' ) ) );

        /*
         * An emptied description means empty. set() drops '' on a string column
         * so the old text used to survive the save with nothing said about it.
         */
        $description = (string) $this->getParam( 'description' );

        if ( $description === '' ) {

            $site->clear( 'description' );

        } else {

            $site->set( 'description', $description );
        }

        $site->save();

        /*
         * Back to the Profile that was edited, not to reporting. This sent
         * every rename to the dashboard, which reads as the save having thrown
         * the screen away -- and is not what the other two Profile editors do.
         *
         * 'edit' as well as the siteId: base.sitesProfile draws the add form
         * without it, so a rename landed on "New Observation Profile".
         */
        $this->set( 'siteId', $this->getParam( 'siteId' ) );
        $this->set( 'edit', true );
        $this->setRedirectAction( 'base.sitesProfile' );
        $this->setStatusCode( 3201 );
    }
}
