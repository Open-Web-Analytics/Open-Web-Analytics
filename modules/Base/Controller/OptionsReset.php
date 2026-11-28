<?php
namespace OWA\Module\Base\Controller;

/**
 * Options Reset Controller
 */

class OptionsReset extends \OWA\Core\AdminController {

    public function __construct($params) {

        $this->setRequiredCapability('edit_settings');
        // It replaces every setting with its default: never from a link alone.
        $this->setNonceRequired();
        parent::__construct($params);
    }

    public function action() {

        $config = \OWA\Core\CoreAPI::configSingleton();

        $ret = $config->reset($this->get('module'));

        if ($ret) {

            $this->e->notice($this->getMsg(2503));
            $this->setStatusCode(2503);

        } else {

            // Settings::reset() refuses a module that is not installed, and answers its save's failure.
            $this->setStatusCode(3018);
        }

        $this->setRedirectAction('base.optionsGeneral');
    }
}
