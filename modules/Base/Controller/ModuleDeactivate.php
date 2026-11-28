<?php
namespace OWA\Module\Base\Controller;

/**
 * Module Deactivation Controller: deactivates an active module
 * (CoreAPI::deactivateModule()), as cmd=deactivate does.
 */

class ModuleDeactivate extends \OWA\Core\AdminController {

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

        $module = \OWA\Core\CoreAPI::moduleClassFactory( $module )->name;

        if ( ! in_array( $module, \OWA\Core\CoreAPI::getActiveModules(), true ) ) {

            return OptionsModules::refuse( $this->params, 3032, [ $module ] );
        }

        if ( $module === 'base' ) {

            return OptionsModules::refuse( $this->params, 3034, [] );
        }

        $this->setRedirectAction('base.optionsModules');
        $this->setStatusCode( $this->deactivate( $module ) === false ? 3014 : 2502 );
    }

    /**
     * @param  string $module
     * @return bool|void CoreAPI::deactivateModule()'s answer
     */
    protected function deactivate( $module ) {

        return \OWA\Core\CoreAPI::deactivateModule( $module );
    }

}
