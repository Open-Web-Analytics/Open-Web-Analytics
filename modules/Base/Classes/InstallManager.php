<?php
namespace OWA\Module\Base\Classes;

/**
 * Abstract Install Controller
 */


class InstallManager extends \OWA\Core\Base {

    /** The length of the first admin's password when cmd=core-install makes it. */
    const GENERATED_PASSWORD_LENGTH = 24;

    /**
     * The rules the first admin and the first site are checked by, in the
     * web wizard (base.installBase) and cmd=core-install alike: each
     * [ name, value, validation, conf ], as Controller::addValidation()
     * takes them, in order. A user name that is missing or not allowed stops
     * the rest (stopOnError).
     *
     * @param  mixed $user_id
     * @param  mixed $email_address
     * @param  mixed $domain
     * @param  mixed $password
     * @return list<array{0: string, 1: mixed, 2: string, 3: array<string, mixed>}>
     */
    public static function setupRules( $user_id, $email_address, $domain, $password ) {

        return [
            [ 'domain', \OWA\Module\Base\Classes\SiteManager::domainOnly( $domain ), 'required', [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'Site domain is required.' ) ] ],
            [ 'user_id', $user_id, 'required', [ 'stopOnError' => true, 'errorMsg' => \OWA\Core\CoreAPI::t( 'A user name for the admin account is required.' ) ] ],
            [ 'user_id', $user_id, 'userName', [ 'stopOnError' => true ] ],
            // Valid, then required: a missing address fails both, and is said to be missing.
            [ 'email_address', $email_address, 'emailAddress', [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'Enter a valid email address.' ) ] ],
            [ 'email_address', $email_address, 'required', [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'An email address for the admin account is required.' ) ] ],
            [ 'password', $password, 'required', [ 'errorMsg' => \OWA\Core\CoreAPI::t( 'Password is required.' ) ] ],
            [ 'password_length', $password, 'stringLength', [
                'operator' => '>=',
                'length'   => \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH,
                'errorMsg' => sprintf( \OWA\Core\CoreAPI::t( 'The password must be at least %d characters.' ), \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH ),
            ] ],
            [ 'password_max_length', $password, 'stringLength', [
                'operator' => '<=',
                'length'   => \OWA\Module\Base\Entity\User::MAX_PASSWORD_BYTES,
                'errorMsg' => \OWA\Module\Base\Entity\User::passwordTooLongMessage(),
            ] ],
        ];
    }

    /**
     * A password for the first admin: GENERATED_PASSWORD_LENGTH characters
     * from the generator application passwords use (AppPasswords).
     *
     * @return string
     */
    public static function generatePassword() {

        return AppPasswords::randomString( self::GENERATED_PASSWORD_LENGTH );
    }

    /**
     * Whether this installation is installed, as the booted process read it:
     * owa-config.php is there and the stored settings say the install
     * completed. Reads no settings again: a scheduled run keeps what it has.
     *
     * @return bool
     */
    public function isInstalled() {

        return \OWA\Core\CoreAPI::configSingleton()->isConfigFilePresent()
            && (bool) \OWA\Core\CoreAPI::getSetting( 'base', 'install_complete' );
    }

    /**
     * The user name of the first admin account, or null when there is none.
     *
     * @return string|null
     */
    public function adminUserId() {

        $u = \OWA\Core\CoreAPI::entityFactory( 'base.user' );
        $u->getByColumn( 'role', \OWA\Module\Base\Entity\User::ADMIN_USER_ROLE );

        return $u->get( 'id' ) ? (string) $u->get( 'user_id' ) : null;
    }

    /**
     * Whether any tracked site (Profile) exists.
     *
     * @return bool
     */
    public function hasSite() {

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\Entity::TABLE_PREFIX . 'site' );
        $db->selectColumn( 'id' );
        $db->limit( 1 );

        return (bool) $db->getOneRow();
    }

    /**
     * The data directory as it should be where the web server runs as a user
     * other than this one: writable only by its owner, so the overrides in its
     * conf/ are run (Core\Overrides), but for logs/, the one folder the web
     * server writes (its error log, the file event queue). logs/ is given
     * $web_group (else the OWA directory's group), group write, and setgid, so
     * the files made there keep the group.
     *
     * Changed only where this user owns the directory: OWA never changes
     * another's file modes. What it changed, or could not, is said.
     *
     * @param  string $web_group
     * @return list<string>
     */
    public function secureDataDir( $web_group = '' ) {

        if ( PHP_OS_FAMILY === 'Windows' || ! function_exists( 'posix_geteuid' ) ) {

            return [];
        }

        $me   = posix_geteuid();
        $data = rtrim( \OWA\Core\Env::dataDir(), '/' );
        $out  = [];

        // The queue's own directory too: only the drain writes there.
        foreach ( [ $data, $data . '/conf', $data . '/queue' ] as $dir ) {

            if ( is_dir( $dir ) && @fileowner( $dir ) === $me && ( fileperms( $dir ) & 0022 ) ) {

                $out[] = @chmod( $dir, fileperms( $dir ) & 07755 )
                    ? sprintf( \OWA\Core\CoreAPI::t( '%s is now writable by its owner only.' ), $dir )
                    : sprintf( \OWA\Core\CoreAPI::t( 'Could not remove group and other write from %s.' ), $dir );
            }
        }

        $group = $web_group !== '' ? $web_group : ( posix_getgrgid( (int) @filegroup( \OWA\Core\Env::root() ) )['name'] ?? '' );

        // logs/ and the tracking inbox in it: what the web server writes, and the cron user takes from.
        foreach ( [ $data . '/logs', $data . '/logs/' . TrackerIngest::INBOX ] as $shared ) {

            if ( ! is_dir( $shared ) || is_link( $shared ) || @fileowner( $shared ) !== $me ) {

                continue;
            }

            $has = posix_getgrgid( (int) filegroup( $shared ) )['name'] ?? '';

            if ( $group !== '' && $has !== $group ) {

                $out[] = @chgrp( $shared, $group )
                    ? sprintf( \OWA\Core\CoreAPI::t( '%1$s now belongs to group %2$s.' ), $shared, $group )
                    : sprintf( \OWA\Core\CoreAPI::t( 'Could not give %1$s to group %2$s: make it writable by the user the web server runs as.' ), $shared, $group );
            }

            $mode = fileperms( $shared ) & 07777;
            $want = ( $mode | 02070 ) & ~0002;

            if ( $mode !== $want ) {

                $out[] = @chmod( $shared, $want )
                    ? sprintf( \OWA\Core\CoreAPI::t( '%s is now writable by its group.' ), $shared )
                    : sprintf( \OWA\Core\CoreAPI::t( 'Could not make %s writable by its group.' ), $shared );
            }
        }

        return $out;
    }

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
     * Each active module's missing tables, created from its entities
     * (Module::createMissingTables()). Tables that exist and the schema
     * versions are left as they are. Nothing is created on a server OWA
     * cannot run on: problem says why.
     *
     * @return array{created: list<string>, failed: list<string>, problem: string|null} table names
     */
    public function createMissingTables() {

        $out = [ 'created' => [], 'failed' => [], 'problem' => \OWA\Module\Base\Classes\DatabaseRequirement::problem() ];

        if ( $out['problem'] !== null ) {

            return $out;
        }

        foreach ( \OWA\Core\CoreAPI::serviceSingleton()->modules as $module ) {

            $tables = $module->createMissingTables();

            array_push( $out['created'], ...$tables['created'] );
            array_push( $out['failed'], ...$tables['failed'] );
        }

        return $out;
    }

    /**
     * The active modules with tables but no recorded schema version
     * (Core\Module::hasUnknownSchemaState()), Base first when it is one.
     *
     * @return list<string> module names
     */
    public function unknownStateModules() {

        $out = [];

        foreach ( \OWA\Core\CoreAPI::serviceSingleton()->modules as $name => $module ) {

            if ( $module->hasUnknownSchemaState() ) {

                $out[] = (string) $name;
            }
        }

        usort( $out, static fn ( $a, $b ) => ( $b === 'base' ) <=> ( $a === 'base' ) );

        return $out;
    }

    /**
     * A module's tables moved aside to <table>_before_<stamp>
     * (Core\Module::moveTablesAside()), then created fresh and its required
     * schema version recorded (Core\Module::install()).
     *
     * @param  string $name  a module's name
     * @param  string $stamp yyyymmddHHMMSS
     * @return array{moved: array<string, string>, problem: string} old name => new name; problem '' when none
     */
    public function reinstallModule( $name, $stamp ) {

        $module = \OWA\Core\CoreAPI::serviceSingleton()->getModule( $name ) ?: \OWA\Core\CoreAPI::moduleClassFactory( $name );
        $out    = $module->moveTablesAside( $stamp );

        if ( $out['problem'] === '' && ! $module->install() ) {

            $out['problem'] = $module->install_problem !== ''
                ? $module->install_problem
                : sprintf( \OWA\Core\CoreAPI::t( 'Module %s was not installed. The error log says why.' ), $name );
        }

        return $out;
    }

    /**
     * Base's tables, then those of every other active module, moved aside to
     * <table>_before_<stamp>, and the settings read from the old owa_setting
     * forgotten (Settings::forgetStored()): a new installation follows. Every
     * new name is checked before any table is renamed.
     *
     * @param  string $stamp yyyymmddHHMMSS
     * @return array{moved: array<string, string>, modules: list<string>, problem: string} old name => new name; the other modules moved; problem '' when none
     */
    public function moveAsideForNewInstall( $stamp ) {

        $out     = [ 'moved' => [], 'modules' => [], 'problem' => '' ];
        $modules = \OWA\Core\CoreAPI::serviceSingleton()->modules;

        uksort( $modules, static fn ( $a, $b ) => ( $b === 'base' ) <=> ( $a === 'base' ) );

        // Every new name checked before any table is renamed.
        foreach ( $modules as $module ) {

            $out['problem'] = $module->moveAsideProblem( $stamp );

            if ( $out['problem'] !== '' ) {

                return $out;
            }
        }

        foreach ( $modules as $name => $module ) {

            $moved        = $module->moveTablesAside( $stamp );
            $out['moved'] = array_merge( $out['moved'], $moved['moved'] );

            if ( $moved['problem'] !== '' ) {

                $out['problem'] = $moved['problem'];

                return $out;
            }

            if ( $name !== 'base' && $moved['moved'] ) {

                $out['modules'][] = (string) $name;
            }
        }

        \OWA\Core\CoreAPI::configSingleton()->forgetStored();

        return $out;
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

        } catch ( \Throwable $e ) {

            $db->rollbackTransaction();
            \OWA\Core\CoreAPI::notice( 'Install not completed, nothing saved: ' . $e->getMessage() . '.' );

            return false;
        }

        /*
         * The first Profile's tracking bundle, which its tag loads, as the
         * Add profile screen publishes a new one. After the commit: the bundle
         * is a file, outside the transaction. One that cannot be written now
         * is queued for the scheduler.
         */
        try {

            \OWA\Module\Base\Classes\TrackerBundle::publishNow( (string) $site_id );

        } catch ( \Throwable $e ) {

            \OWA\Core\CoreAPI::notice( 'The first Profile\'s tracking bundle was not published: ' . $e->getMessage() . '. Run cmd=publish-trackers.' );
        }

        return $site_id;
    }

    /**
     * Raises install_complete, for a module to hear: once the install's last
     * step is saved, by the install wizard and by cmd=core-install alike. Base
     * listens for nothing.
     *
     * @param  string $user_id the admin account's user name
     * @param  string $site_id the first site
     * @return void
     */
    public function announceInstallComplete( $user_id, $site_id ) {

        $ed    = \OWA\Core\CoreAPI::getEventDispatch();
        $event = $ed->eventFactory();
        $event->set( 'u', $user_id );
        $event->set( 'site_id', $site_id );
        $event->setEventType( 'install_complete' );
        $ed->notify( $event );
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
