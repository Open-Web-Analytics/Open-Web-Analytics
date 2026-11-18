<?php
namespace OWA\Module\Base\Controller;

/**
 * Installation Finish
 */

// needed??
class InstallFinish extends \OWA\Core\Controller\Install {

    /**
     * Writes nothing, and shows nothing of the install.
     *
     * It set the install-complete flag, on a GET with no nonce: the flag is set
     * by base.installBase, in the same transaction as the admin and the first
     * site (InstallManager::finishInstall()), and base.installBase renders the
     * finish screen itself. An installed site never gets here
     * (Core\Controller\Install::doAction() redirects); anyone else is sent to
     * the start.
     */
    public function action() {

        $this->setRedirectAction( 'base.installStart' );
    }
}
