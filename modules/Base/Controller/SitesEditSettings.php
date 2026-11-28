<?php
namespace OWA\Module\Base\Controller;

/**
 * Edit User Controller
 * 
 *
 * @phpstan-import-type FieldSet from \OWA\Module\Base\Classes\Settings
 */

class SitesEditSettings extends \OWA\Core\AdminController {

    // edit_sites, and access to the Property of the Profile the request names.
    use \OWA\Core\PropertyScopedAccess;

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

        // Each setting the screen would store, checked against its declaration.
        foreach ( self::scopedFieldSets() as $set ) {

            foreach ( \OWA\Module\Base\Classes\SettingsForm::scopedProblems(
                          $set, 'profile', (string) $this->getParam( 'siteId' ),
                          $this->getParam( 'config' ), $this->getParam( 'override' ) ) as $name => $problem ) {

                $this->addValidationError( $name, $problem );
            }
        }
    }

    /**
     * The fieldsets the Observation Settings screen shows (templates/profile_settings.php).
     *
     * @return list<FieldSet>
     */
    private static function scopedFieldSets() {

        return \OWA\Module\Base\Classes\SettingsForm::groupFieldSets( 'profile_observation' );
    }

    public function action() {

        $site_id = $this->getParam( 'siteId' );

        /*
         * One scoped row per setting the screen shows, decided by each field's
         * Override switch: on stores the value at this Profile, off removes any
         * value it holds. See SettingsForm::saveScoped().
         */
        $saved = true;

        foreach ( self::scopedFieldSets() as $set ) {

            $saved = \OWA\Module\Base\Classes\SettingsForm::saveScoped(
                $set, 'profile', (string) $site_id,
                (array) $this->getParam( 'config' ), (array) $this->getParam( 'override' ) ) && $saved;
        }

        if ( $saved ) {

            $this->setStatusCode( 3201 );
        }

        // Back to the screen the form is on, not to the Profile's Details.
        $this->set('siteId', $site_id);
        $this->setRedirectAction( 'base.profileSettings' );
    }

    public function errorAction() {
        $owa_site_id = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );
        $this->settingsChrome( [ 'siteId' => $owa_site_id ], $owa_site_id );
        /*
         * The screen the form is on, as base.profileSettings renders it.
         *
         * Drawn here rather than redirected to, as PropertyEdit's refusal is:
         * a redirect is a GET, and what the form sent would not reach it.
         * base.sitesProfile is the Profile's details form, which reads none of
         * these fields.
         */
        $this->setSubview( 'base.profileSettings', ProfileSettings::SCREEN );
        /*
         * 3002 -- "the form had errors" -- not 3311, which is the CLI-updates
         * message. Controller::doAction() sets validation_errors before calling
         * errorAction() and msgs.php shows error_msg only when there are none, so
         * a wrong code here shows only once that branch changes.
         */
        $this->set('error_code', 3002);
        $site = \OWA\Module\Base\Entity\Site::forSiteId( $owa_site_id );
        $this->set('site', $site->_getProperties());

        /*
         * What the form sent -- each value and each switch -- so correcting the
         * reported error and saving again does not write over the rest.
         */
        $this->set( 'posted', [
            'config'   => (array) $this->getParam( 'config' ),
            'override' => (array) $this->getParam( 'override' ),
        ] );

        // Also the resolved id, so the form posts back to the site it is showing.
        $this->set( 'siteId', $owa_site_id );
    }
}
