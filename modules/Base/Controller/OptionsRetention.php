<?php
namespace OWA\Module\Base\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The install's Data Retention page: how long event data is kept, and the
 * reporting window every Property starts from (Classes\Retention).
 */
class OptionsRetention extends \OWA\Core\AdminController {

    public function __construct( $params ) {

        parent::__construct( $params );

        $this->type = 'options';
        $this->setRequiredCapability( 'edit_settings' );
    }

    public function action() {

        $owa_site_id = $this->resolveCurrentSiteId();

        $this->settingsChrome( array( 'siteId' => $owa_site_id ), '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $owa_site_id ) );
        $this->setSubview( 'base.optionsRetention', [
            'template' => 'options_retention.php',
            'vars'     => [ 'settings_fieldsets' => \OWA\Module\Base\Classes\SettingsForm::pageFieldSets( 'base.optionsRetention' ) ] ] );

        return $this->data;
    }
}

?>
