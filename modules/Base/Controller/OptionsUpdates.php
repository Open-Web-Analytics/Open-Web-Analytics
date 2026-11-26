<?php
namespace OWA\Module\Base\Controller;

/**
 * The install's Updates page: what automatic updates will do, read from the
 * updates directory (Classes\AutoUpdate\UpdatesState), the actions the
 * scheduler runs for it (base.updatesRun), and the settings.
 */
class OptionsUpdates extends \OWA\Core\AdminController {

    public function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'edit_settings' );
    }

    public function action() {

        $owa_site_id = $this->resolveCurrentSiteId();
        $this->settingsChrome( [ 'siteId' => $owa_site_id ], '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $owa_site_id ) );
        $this->setSubview( 'base.optionsUpdates', [
            'template' => 'options_updates.php',
            'vars'     => [
                'settings_fieldsets' => \OWA\Module\Base\Classes\SettingsForm::pageFieldSets( 'base.optionsUpdates' ),
                'updates_state'      => \OWA\Module\Base\Classes\AutoUpdate\UpdatesState::read(),
            ] ] );

        return $this->data;
    }
}
