<?php
namespace OWA\Module\Base\Controller;

/**
 * Module Deactivation Controller
 */

class ModuleDeactivate extends \OWA\Core\AdminController {

    public function __construct($params) {

        $this->setRequiredCapability('edit_modules');
        $this->setNonceRequired();

        parent::__construct($params);

    }

    public function action() {

        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $m = $s->getModule($this->getParam('module'));
        $this->setRedirectAction('base.optionsModules');
        $this->setStatusCode($m->deactivate() === false ? 3014 : 2502);
    }

}
