<?php
namespace OWA\Module\Base\Controller;

/**
 * Options Modules Roster Controller
 */

class OptionsModules extends \OWA\Core\AdminController {

    public function __construct($params) {

        $this->setRequiredCapability('edit_modules');
        parent::__construct($params);
    }

    public function action() {

        $dirs = [];

        // Only directories that hold a module (CoreAPI::getPresentModules()).
        foreach ( (array) \OWA\Core\CoreAPI::getPresentModules() as $file ) {

            $mod = \OWA\Core\CoreAPI::moduleClassFactory($file);
            $dirs[$file]['name'] = $mod->name;
            $dirs[$file]['display_name'] = $mod->display_name;
            $dirs[$file]['author'] = $mod->author;
            $dirs[$file]['group'] = $mod->group;
            $dirs[$file]['version'] = $mod->version;
            $dirs[$file]['description'] = $mod->description;
            $dirs[$file]['config_required'] = $mod->config_required;
            $dirs[$file]['current_schema_version'] = $mod->getSchemaVersion();
            $dirs[$file]['required_schema_version'] = $mod->getRequiredSchemaVersion();
            $dirs[$file]['schema_uptodate'] = $mod->isSchemaCurrent();
            $dirs[$file]['activated_by'] = \OWA\Core\Module::activatedByConstant( $mod->name );
        }

        ksort($dirs);

        // remove base module so it can't be deactivated
        // unset($dirs['base']);

        // getActiveModules() returns runtime module names (the lowercase config
        // keys, e.g. 'maxmind_geoip'), but $dirs is keyed by on-disk directory name,
        // which is PascalCase post-PSR-4 (e.g. 'MaxmindGeoip'). Match on the stored
        // runtime name ($mod->name) rather than the directory key so the roster
        // renders Deactivate for active modules.
        $active_modules = array_flip( \OWA\Core\CoreAPI::getActiveModules() );

        foreach ($dirs as $dir => $info) {

            if (isset($info['name']) && isset($active_modules[$info['name']])):
                $dirs[$dir]['status'] = 'active';
            endif;
        }

        // add data to container
        $owa_site_id = $this->resolveCurrentSiteId();
        $this->settingsChrome( [ 'siteId' => $owa_site_id ], '', \OWA\Module\Base\Classes\Cube\Cubes::propertyIdForSite( $owa_site_id ) );
        $this->setSubview( 'base.optionsModules', [ 'template' => 'options_modules.php', 'vars' => [ 'modules' ] ] );
        $this->set('modules', $dirs);

        return;

    }

}
