<?php
namespace OWA\Module\Base\Controller;

class InstallStart extends \OWA\Core\Controller\Install {
    
    public function action() {

        $this->setView('base.install');
        $this->setSubview( 'base.installStart', [
            'template' => 'install_start.php', 'vars' => [ 'headline' => \OWA\Core\CoreAPI::t( 'Get Started...' ) ] ] );
    }
}
