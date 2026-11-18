<?php
namespace OWA\Module\Base\Controller;

/**
 * Admin Settings/Options Controller
 */

class OptionsGeneral extends \OWA\Core\AdminController {

    public function __construct($params) {

        parent::__construct($params);
        $this->type = 'options';
        $this->setRequiredCapability('edit_settings');

    }

    public function action() {

        // add data to container
        $owa_site_id = $this->resolveCurrentSiteId();
        $this->settingsChrome( [ 'siteId' => $owa_site_id ], '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $owa_site_id ) );
        // What the page renders, from the page's own registration. The template
        // loops over these; it does not know which settings it is showing.
        $this->setSubview( 'base.optionsGeneral', [
            'template' => 'options_general.php',
            'vars'     => [ 'settings_fieldsets' => \OWA\Module\Base\Classes\SettingsForm::pageFieldSets( 'base.optionsGeneral' ) ] ] );

        return $this->data;

    }

}
