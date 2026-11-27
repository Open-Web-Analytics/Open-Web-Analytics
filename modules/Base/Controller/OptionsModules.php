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

    /**
     * Whether $name is a module in modules/ (CoreAPI::getPresentModules()),
     * by its runtime name (maxmind_geoip) or its directory (MaxmindGeoip).
     *
     * @param  mixed $name
     * @return bool
     */
    public static function isPresent( $name ) {

        return is_string( $name ) && preg_match( '/^[A-Za-z0-9_]+$/', $name )
            && in_array( \OWA\Core\Lib::moduleDirName( $name ), (array) \OWA\Core\CoreAPI::getPresentModules(), true );
    }

    /**
     * This screen, drawn with a refusal from base.moduleActivate or
     * base.moduleDeactivate.
     *
     * @param  array<string, mixed> $params the refused request's
     * @param  int                  $code   a message (conf/messages.php)
     * @param  list<string>         $values its message's substitutions
     * @return mixed this screen's answer
     */
    public static function refuse( array $params, $code, array $values ) {

        $screen = new self( $params );
        $screen->set( 'error_msg', $screen->getMsg( $code, [ 'message' => $values ] ) );

        return $screen->doAction();
    }

}
