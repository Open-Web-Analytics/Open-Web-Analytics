<?php
namespace OWA\Module\Base\Controller;

/**
 * Install Configuration Controller
 */

class InstallDefaultsEntry extends \OWA\Core\Controller\Install {

    /** The form, which InstallBase draws again, filled in, when the install fails. */
    const SCREEN = [ 'template' => 'install_defaults_entry.php', 'vars' => [ 'defaults' ] ];

    public function action() {

        $this->setView('base.install');
        $this->setSubview( 'base.installDefaultsEntry', self::SCREEN );
    }
}
