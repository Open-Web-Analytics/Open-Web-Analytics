<?php
namespace OWA\Module\Base\Controller;

/**
 * The install's Data Retention page: how long event data is kept, and the
 * reporting window every Property starts from (Classes\Retention).
 */
class OptionsRetention extends \OWA\Core\AdminController {

    public function __construct( $params ) {

        parent::__construct( $params );

        $this->setRequiredCapability( 'edit_settings' );
    }

    public function action() {

        $owa_site_id = $this->resolveCurrentSiteId();

        $this->settingsChrome( [ 'siteId' => $owa_site_id ], '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $owa_site_id ) );
        $this->setSubview( 'base.optionsRetention', [
            'template' => 'options_retention.php',
            'vars'     => [ 'settings_fieldsets' => \OWA\Module\Base\Classes\SettingsForm::pageFieldSets( 'base.optionsRetention' ) ] ] );

        return $this->data;
    }
}
