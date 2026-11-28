<?php
namespace OWA\Module\Base\Controller;

/**
 * Module Activation Controller: installs and activates a module in
 * modules/ (CoreAPI::installModule()), as cmd=activate does.
 */

class ModuleActivate extends \OWA\Core\AdminController {

    public function __construct($params) {

        $this->setRequiredCapability('edit_modules');
        $this->setNonceRequired();
        parent::__construct($params);
    }

    public function action() {

        $module = $this->getParam('module');

        if ( ! is_string( $module ) || $module === '' ) {

            return OptionsModules::refuse( $this->params, 3033, [] );
        }

        if ( ! OptionsModules::isPresent( $module ) ) {

            return OptionsModules::refuse( $this->params, 3031, [ $module ] );
        }

        if ( ! $this->install( $module, $problem ) ) {

            return OptionsModules::refuse( $this->params, 3030,
                [ $module, $problem !== '' ? $problem : \OWA\Core\CoreAPI::t( 'The error log says why.' ) ] );
        }

        $this->setRedirectAction( 'base.optionsModules' );
        $this->setStatusCode( 2501 );
    }

    /**
     * @param  string      $module
     * @param  string|null $problem set to why it failed, '' when it did not
     * @return bool|void CoreAPI::installModule()'s answer
     */
    protected function install( $module, &$problem ) {

        return \OWA\Core\CoreAPI::installModule( $module, $problem );
    }

}
