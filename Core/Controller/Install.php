<?php
namespace OWA\Core\Controller;

/**
 * Abstract Install Controller
 */


class Install extends \OWA\Core\Controller {

    /** The wizard's password fields, never drawn back after a failed nonce. */
    const PASSWORD_PARAMS = [ 'password', 'db_password' ];

    /** @var \OWA\Module\Base\Classes\InstallManager */
    public $im;

    /**
     * @param array<string, mixed> $params request params: name => value, as sent
     */
    public function __construct( $params ) {
        
        
        // needed just in case a re-install happens and updates are also needed.
        // tells the controller to skip the updates redirect. Also tells the main owa_caller
        // class not to load the config from the DB which doesn't exist during the install.
        if (!defined('OWA_INSTALLING')) {
            define('OWA_INSTALLING', true);
        }
        
        $this->setRequiredCapability('install_schema');
          
        $this->im = new \OWA\Module\Base\Classes\InstallManager();
        
        parent::__construct( $params );
    }

    /**
     * Nothing of the installer runs on an installed system: every install
     * screen and action redirects to the install's public URL instead.
     *
     * Here rather than in pre(): Controller::doAction() goes on to action()
     * whatever pre() returns, so a redirect header from pre() would still let
     * base.installBase re-run the schema install and add a site.
     *
     * @return mixed an empty array after the redirect, else parent::doAction()'s
     *               result: whatever the action returned
     */
    public function doAction() {

        if ( $this->isInstallComplete() ) {

            \OWA\Core\CoreAPI::debug( 'Install is already complete. redirecting to public url' );
            $this->redirectBrowserToUrl( \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' ) );

            // No view: the redirect is the whole response.
            return [];
        }

        return parent::doAction();
    }

    /**
     * A post whose nonce failed: the same step drawn again (errorAction()),
     * with the values submitted except the passwords, and message 2005.
     *
     * @return mixed errorAction()'s answer
     */
    public function nonceFailedAction() {

        $this->params = array_diff_key( $this->params, array_flip( self::PASSWORD_PARAMS ) );
        $this->set( 'error_msg', $this->getMsg( 2005 ) );

        return $this->errorAction();
    }

    /**
     * @return bool false when the database is refused or a table was not created
     */
    public function installSchema() {
        
        return $this->im->installSchema();
    }

    /**
     * @param  string $user_id
     * @param  string $email_address
     * @param  string $password
     * @return string|false|null the password; false when it is empty; null when
     *                           an admin or the user_id already exists
     */
    public function createAdminUser( $user_id, $email_address, $password = '' ) {

        return $this->im->createAdminUser( $user_id, $email_address, $password );
    }

    /**
     * @param  string $domain
     * @param  string $name
     * @param  string $description
     * @param  string $site_family
     * @param  string $site_id
     * @return string|false the site_id, or false when the site was not created
     */
    public function createDefaultSite( $domain, $name = '', $description = '', $site_family = '', $site_id = '' ) {
        
        return $this->im->createDefaultSite( $domain, $name, $description, $site_family, $site_id );
    }

    /**
     * @return bool
     */
    public function checkDbConnection() {
        
        return $this->im->checkDbConnection();
    }
    
    /**
     * @return bool
     */
    public function isInstallComplete() {
        
        return $this->im->isInstallComplete();
    }
}
