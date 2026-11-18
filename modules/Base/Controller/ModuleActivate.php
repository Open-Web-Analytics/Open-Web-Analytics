<?php
namespace OWA\Module\Base\Controller;

/**
 * Module Activation Controller
 */

class ModuleActivate extends \OWA\Core\AdminController {

    public function __construct($params) {

        $this->setRequiredCapability('edit_modules');
        $this->setNonceRequired();
        parent::__construct($params);
    }

    public function action() {

        $module = $this->getParam('module');

        if ( $module ) {
            $ret = \OWA\Core\CoreAPI::installModule($module);
        }

        $data = [];

        $data['do'] = 'base.optionsModules';
        $data['view_method'] = 'redirect';
        $data['status_code'] = 2501;

        return $data;

    }

}
