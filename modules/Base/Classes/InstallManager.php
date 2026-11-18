<?php
namespace OWA\Module\Base\Classes;

/**
 * Abstract Install Controller
 */


class InstallManager extends \OWA\Core\Base {

    /**
     * Create the schema, on a server OWA can run on.
     *
     * @return bool
     */
    public function installSchema() {

        /*
         * Refused before a table exists, on a server OWA cannot run on. The web
         * wizard and the CLI installer both come through here.
         */
        $problem = \OWA\Module\Base\Classes\DatabaseRequirement::problem();

        if ( $problem !== null ) {

            \OWA\Core\CoreAPI::error( $problem );

            return false;
        }

        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $base = $service->getModule('base');
        $status = $base->install();
        return $status;

    }

    /**
     * Create the first admin, unless there is one.
     *
     * @param  string $user_id
     * @param  string $email_address
     * @param  string $password
     * @return string|false|void  the password; false without one; nothing when an
     *                            admin, or a user with that user_id, exists
     */
    public function createAdminUser($user_id, $email_address, $password = '') {

        //create user entity
        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        // check to see if an admin user already exists
        $u->getByColumn('role', 'admin');
        $id_check = $u->get('id');
        // if not then proceed
        if (empty($id_check)) {

            //Check to see if user name already exists
            $u->getByColumn('user_id', $user_id);

            $id = $u->get('id');

            // Set user object Params
            if (empty($id)) {

                // The first admin has no invitation to accept: nobody could
                // send it, and nobody could sign in to send another.
                if ( ! $password ) {

                    \OWA\Core\CoreAPI::error( 'The admin account needs a password.' );

                    return false;
                }

                $ret = $u->createNewUser($user_id, \OWA\Module\Base\Entity\User::ADMIN_USER_ROLE, $password, $email_address, \OWA\Module\Base\Entity\User::ADMIN_USER_REAL_NAME);

                /*
                 * Users live in the Organization -- that is the whole reason
                 * the top tier exists. Set after creation rather than through
                 * createNewUser(), whose signature is a public seam this does
                 * not need to widen.
                 */
                $sm = new \OWA\Module\Base\Classes\SiteManager();
                $u->getByColumn( 'user_id', $user_id );

                if ( $u->get( 'id' ) ) {

                    $u->set( 'organization_id', $sm->ensureOrganization() );
                    $u->update();
                }
                \OWA\Core\CoreAPI::debug("Admin user created successfully.");
                return $password;

            } else {
                \OWA\Core\CoreAPI::debug($this->getMsgAsString(3306));
            }
        } else {
            \OWA\Core\CoreAPI::debug("Admin user already exists.");
        }

    }

    /**
     * Ensure the install's default site exists, and return its site_id.
     *
     * Idempotent: identifiers are minted, so the domain is looked up directly.
     *
     * A caller may still pin an identifier by passing $site_id -- the install
     * wizard does -- and in that case the pinned value is what gets looked up,
     * so pinning stays idempotent on its own terms.
     *
     * @param  string $domain
     * @param  string $name         the domain when empty
     * @param  string $description
     * @param  string $site_family
     * @param  string $site_id      minted when empty
     * @return string|false the site_id; false when the site was not created
     */
    public function createDefaultSite($domain, $name = '', $description = '', $site_family = '', $site_id = '') {

        $domain = \OWA\Module\Base\Classes\SiteManager::domainOnly($domain);

        if (!$name) {
            $name = $domain;
        }

        \OWA\Core\CoreAPI::notice('Checking for existence of default site.');

        if ($site_id) {
            $existing = \OWA\Module\Base\Entity\Site::forSiteId( $site_id );
        } else {
            $existing = \OWA\Core\CoreAPI::entityFactory('base.site');
            $existing->load($domain, 'domain');
        }

        if ($existing->wasPersisted()) {

            \OWA\Core\CoreAPI::notice(sprintf(
                "Default site already exists (id = %s). nothing to do here.", $existing->get('id')));

            return $existing->get('site_id');
        }

        $site = \OWA\Core\CoreAPI::entityFactory('base.site');

        if (!$site_id) {
            $site_id = $site->mintSiteId();
        }

        /*
         * The first site is a Profile like any other, and gets a Property like
         * any other. Without this a fresh install has the hierarchy TABLES but
         * nothing in them, so its one site shows under "Unassigned" in both the
         * site selector and the Property roster -- while a migrated install of
         * the same age looks correct. The two must not differ.
         */
        $sm          = new \OWA\Module\Base\Classes\SiteManager();
        $property_id = $sm->ensurePropertyFor( $domain, $name, $description );

        $site->set('id', $site->generateId($site_id));
        $site->set('site_id', $site_id);
        $site->set('property_id', $property_id);
        // The given name describes the website, so it went to the Property.
        $site->set('name', $property_id ? $sm->nextProfileName( $property_id ) : $name);
        $site->set('description', $description);
        $site->set('domain', $domain);
        $site->set('site_family', $site_family);

        if ( ! $site->create() ) {

            // false, so the install step can roll back (finishInstall()).
            \OWA\Core\CoreAPI::notice('Creation of default site failed.');

            return false;
        }

        \OWA\Core\CoreAPI::notice('Created default site.');

        return $site->get('site_id');
    }

    /**
     * Whether the install has an admin user.
     *
     * @return bool
     */
    public function hasAdminUser() {

        $u = \OWA\Core\CoreAPI::entityFactory('base.user');
        $u->getByColumn('role', \OWA\Module\Base\Entity\User::ADMIN_USER_ROLE);

        return (bool) $u->get('id');
    }

    /**
     * The install's writes after the schema, all or none: the admin user, the
     * first site, the settings (the reporting timezone with them) and the
     * install-complete flag.
     *
     * One transaction, so a failure part way leaves the schema and nothing
     * else, and running the step again finishes the install. The schema is not
     * in it: MySQL commits CREATE TABLE on the spot, and installSchema() is
     * CREATE TABLE IF NOT EXISTS, so a second run of it is harmless.
     *
     * @param callable $writes does the writes; returns the site_id, or false
     * @return string|false the site_id, or false when nothing was saved
     */
    public function finishInstall( callable $writes ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->beginTransaction();

        try {

            $site_id = $writes();

            if ( ! $site_id || ! $this->hasAdminUser() ) {

                throw new \RuntimeException( 'the admin user or the first site was not created' );
            }

            \OWA\Core\CoreAPI::configSingleton()->persistSetting( 'base', 'install_complete', true );

            if ( ! \OWA\Core\CoreAPI::configSingleton()->save() ) {

                throw new \RuntimeException( 'the settings were not saved' );
            }

            $db->endTransaction();

            return $site_id;

        } catch ( \Throwable $e ) {

            $db->rollbackTransaction();
            \OWA\Core\CoreAPI::notice( 'Install not completed, nothing saved: ' . $e->getMessage() . '.' );

            return false;
        }
    }

    /** @return bool */
    public function checkDbConnection() {

        // Check DB connection status
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->connect();
        if ($db->connection_status === true) {
            return true;
        } else {
            return false;
        }
    }
    
    /** @return bool */
    public function isInstallComplete() {
        
        // is config file present?
        $c = \OWA\Core\CoreAPI::configSingleton();
        if ( ! $c->isConfigFileLoaded() ) {
            
            return false;
        }
        
        // Can a connection be made? Asked quietly: on the installer's first
        // page a config whose details do not connect means "not installed",
        // and the environment check reports the failure.
        $db = \OWA\Core\CoreAPI::dbSingleton();
        if ( ! $db->canConnect() ) {
            
            return false;
        }
        
        // is base schema installed?
        if ( ! $this->isBaseSchemaInstalled() ) {
            
            return false;
        } else {
            
            \OWA\Core\CoreAPI::debug('base schema install check passed.');
        }
        
        // load config from DB
        $c->load( \OWA\Core\CoreAPI::getSetting( 'base', 'configuration_id' ) );
        
        // is the install flag set
        if ( ! \OWA\Core\CoreAPI::getSetting('base', 'install_complete') ) {
            
            return false;
        }
        
        return true;
    }
    
    /**
     * Check to see if schema is installed
     *
     * @return boolean
     */
    public function isBaseSchemaInstalled() {
        
        $base_module_tables = ['user'];
        $tables_missing = [];
        
        $db = \OWA\Core\CoreAPI::dbSingleton();
       
        // test for base tables
        foreach ( $base_module_tables as $table ) {
            
            \OWA\Core\CoreAPI::debug('Testing for existence of table: '. $table);
            
            $check = $db->get_results(sprintf(OWA_SQL_SHOW_TABLE, \OWA\Core\Entity::TABLE_PREFIX . $table ) );
           
            // if a table is missing add it to this array
            if (empty($check)) {
            
                $tables_missing[] = $table;
                
                \OWA\Core\CoreAPI::debug('Did not find table: '. $table);
            }
        }
    
        if ( $tables_missing ) {
           
            \OWA\Core\CoreAPI::debug( 'Base Schema is missing tables:', $tables_missing );
    
            return false;
            
        } else {
            
            return true;
        }
    }
}
