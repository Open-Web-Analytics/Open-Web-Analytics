<?php
namespace OWA\Module\Base\Controller;

use OWA\Core\CoreAPI;
use OWA\Module\Base\Classes\AutoUpdate\Install;
use OWA\Module\Base\Classes\InstallManager;

/**
 * Installs OWA: a new installation's database, or a release's code.
 *
 *   php cli.php cmd=core-install user_id=<name> email_address=<email> domain=<url>
 *       no installation yet: OWA_SECRET, the schema, the first admin (its
 *       password generated and printed once, or read with --password-stdin)
 *       and the first site, then the data directory's permissions
 *   php cli.php cmd=core-install
 *       installed: the permissions checked, missing tables created, pending
 *       schema updates applied, stale tracking bundles republished; the admin
 *       and the sites are not changed
 *   php cli.php cmd=core-install --force
 *       installed: each module with tables but no recorded schema version
 *       has its tables moved aside and is installed fresh, Base with the
 *       setup arguments; then as above
 *   php cli.php cmd=core-install --version=2.0.3 [--force]
 *   php cli.php cmd=core-install --file=owa_2.0.3_packaged.tar [--sig=<path>] [--force]
 *       installed: that release's code (Classes\AutoUpdate\Install), fetched
 *       through the update host or read from the tarball, and proved by its
 *       signature either way
 *
 * A release's files are copied when the command has finished, and the next
 * run of cli.php finishes that install. cmd=core-update is the automatic
 * routine the scheduler runs.
 */
class CoreInstallCli extends \OWA\Core\Controller\Cli {

    /** May be queued (JobQueue::isQueueable()): The Updates screen's Install queues it. */
    const QUEUEABLE = true;

    /**
     * Install outcomes that are retried; every other failure gives up
     * (Cli::fail( $msg, true )). Only BUSY, another install running, can
     * change within minutes. A refusal names something about this server or
     * the version asked for, and a failed install is tried again on the
     * release check's own schedule (Install), never at once.
     */
    const RETRIED = [ Install::BUSY ];

    /** The arguments that set up a new installation: ignored, and said, on an installed one. */
    const SETUP_ARGUMENTS = [ 'user_id', 'email_address', 'domain', 'description', 'site_family', 'password-stdin' ];

    /** The validation rules (InstallManager::setupRules()) named for the argument they check, where the two differ. */
    const RULE_ARGUMENTS = [
        'password'            => '--password-stdin',
        'password_length'     => '--password-stdin',
        'password_max_length' => '--password-stdin',
    ];

    /** @var bool|null whether OWA is installed, read once */
    private $installed = null;

    /** @var string|null the first admin's password: read from standard input, or generated */
    private $password = null;

    /** @var bool whether $password was generated, and so is printed */
    private $generated = false;

    /** @var InstallManager|null */
    private $install_manager = null;

    /** A release is installed from a full server only (Install::byHand()). */
    public static function serverRoles() {

        return [ \OWA\Core\ServerRole::FULL ];
    }

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        $min = \OWA\Module\Base\Entity\User::MIN_PASSWORD_LENGTH;
        $max = \OWA\Module\Base\Entity\User::MAX_PASSWORD_BYTES;

        return [
            'description' => 'Installs OWA. With no installation yet (owa-config.php must exist), gives owa-config.php a generated OWA_SECRET where it has none or the example one, then sets up the database: the schema, the first admin and the first tracked website, then the data directory\'s permissions. The admin\'s password is generated and printed once, unless --password-stdin is given. '
                . 'On an installed system with no release named, in this order: the permissions are checked, missing tables are created, pending schema updates are applied as cmd=core-update-schema applies them, and stale tracking bundles are republished as cmd=publish-trackers does. The admin and the sites are not changed. A module with tables but no recorded schema version stops it, unless --force is given. '
                . 'With --version or --file, installs that release\'s code, whatever the automatic update settings say: fetched by its version through OWA\'s update host (auto_update_download_url), or read from a tarball, and checked against its signature either way. Any release can be named whose requirements this server meets and which installs over the installed release (its upgrades_from); a newer major version too. Copies its files when the command has finished, and the next run of cli.php runs its schema updates and republishes the tracking bundles. In each directory the release ships, files it does not ship are deleted. A copy that fails puts the installed release back. Clears a failed install of it, which automatic updates skip.',
            'arguments'   => [
                'user_id' => [
                    'type'        => 'string',
                    'value'       => '<name>',
                    'description' => 'A new installation: the admin account\'s user name. Required there.',
                ],
                'email_address' => [
                    'type'        => 'string',
                    'value'       => '<email>',
                    'description' => 'A new installation: the admin account\'s email address, used for password recovery. Required there.',
                ],
                'domain' => [
                    'type'        => 'string',
                    'value'       => '<url>',
                    'description' => 'A new installation: the domain of the first website to track. Required there.',
                ],
                'description' => [
                    'type'        => 'string',
                    'value'       => '<text>',
                    'description' => 'A new installation: a description of the first website.',
                ],
                'site_family' => [
                    'type'        => 'string',
                    'value'       => '<text>',
                    'description' => 'A new installation: a family name to group the first website under.',
                ],
                '--password-stdin' => [
                    'type'        => 'flag',
                    'description' => sprintf( 'A new installation: read the admin account\'s password from standard input, one line, at least %d characters and at most %d bytes, in place of a generated one.', $min, $max ),
                ],
                'password' => [
                    'retired' => 'password= is not taken: a password on the command line is seen by other users and kept in shell history. Leave it out to have one generated and printed once, or give --password-stdin and pipe it in.',
                ],
                'web-group' => [
                    'type'        => 'string',
                    'value'       => '<group>',
                    'description' => 'The group the web server runs as, which owa-data/logs/ is given. Default: the OWA directory\'s group.',
                ],
                '--version' => [
                    'type'        => 'string',
                    'value'       => '<x.y.z>',
                    'description' => 'An installed system: the release to install, fetched through the update host.',
                ],
                '--file' => [
                    'type'        => 'string',
                    'value'       => '<path>',
                    'description' => 'An installed system: the release in this tarball, installed with no network request. Its version is read from it.',
                ],
                '--sig' => [
                    'type'        => 'string',
                    'value'       => '<path>',
                    'description' => 'With --file: the tarball\'s signature. Default: the tarball\'s path with .sig added.',
                ],
                '--force' => [
                    'type'        => 'flag',
                    'description' => 'With --version or --file: install a release older than the installed one. The installed release again needs no --force: it is reinstalled, copying the files that differ. Never one older than the database schema: the rollbacks to run first are listed instead. With no release named: each module with tables but no recorded schema version has its tables renamed <table>_before_<yyyymmddHHMMSS>, not dropped, and is installed fresh. When that module is Base, it is a new installation, and the setup arguments are required.',
                ],
            ],
        ];
    }

    public function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );

        parent::__construct( $params );
    }

    /** The release that fixes a failed schema update installs while it is pending. */
    public function needsCurrentSchema() {

        return false;
    }

    /**
     * A new installation's admin and first site, checked by the rules the
     * web wizard uses (InstallManager::setupRules()). Nothing is checked on
     * an installed system, or when a release is named, which action() refuses
     * before anything is installed.
     */
    public function validate() {

        if ( $this->releaseNamed() || ( $this->isInstalled() && ! $this->baseForced() ) ) {

            return;
        }

        foreach ( InstallManager::setupRules( $this->param( 'user_id' ), $this->param( 'email_address' ),
                $this->param( 'domain' ), $this->adminPassword() ) as [ $name, $value, $validation, $conf ] ) {

            $this->addValidation( $name, $value, $validation, $conf );
        }
    }

    /** Refused arguments: the usage error, each naming its argument. Nothing was done. */
    public function errorAction() {

        $problems = [];

        foreach ( (array) ( $this->data['validation_errors'] ?? [] ) as $name => $msg ) {

            $problems[ $name ] = sprintf( '%s: %s', self::RULE_ARGUMENTS[ $name ] ?? $name, is_array( $msg ) ? implode( ' ', $msg ) : (string) $msg );
        }

        $this->usageError( self::usageMessage( 'core-install', $problems ) );
    }

    public function action() {

        $problem = $this->combinationProblem();

        if ( $problem !== null ) {

            return $this->usageError( self::usageMessage( 'core-install', [ 'arguments' => $problem ] ) );
        }

        if ( ! $this->isInstalled() ) {

            if ( $this->releaseNamed() ) {

                return $this->fail( CoreAPI::t( 'OWA is not installed yet. Run core-install with the admin and site arguments first.' ), true );
            }

            return $this->setUp();
        }

        if ( $this->releaseNamed() ) {

            $this->sayIgnored( array_merge( self::SETUP_ARGUMENTS, [ 'web-group' ] ) );

            return $this->installRelease();
        }

        if ( $this->param( 'force' ) ) {

            return $this->installUnknownFresh();
        }

        $this->sayIgnored( self::SETUP_ARGUMENTS );

        return $this->checkAndRepair();
    }

    /**
     * Whether Base is to be installed fresh: --force, no release named, and
     * Base's tables in an unknown state (Core\Module::hasUnknownSchemaState()).
     *
     * @return bool
     */
    private function baseForced() {

        return $this->param( 'force' ) && ! $this->releaseNamed()
            && in_array( 'base', $this->installManager()->unknownStateModules(), true );
    }

    /**
     * An installed system, --force and no release named: each module with
     * tables but no recorded schema version has them moved aside and is
     * installed fresh, then checkAndRepair(). Base is a new installation
     * (setUp()), after the tables of every active module are moved aside,
     * and those modules are installed fresh after it.
     *
     * @return null
     */
    private function installUnknownFresh() {

        $im      = $this->installManager();
        $unknown = $im->unknownStateModules();
        $stamp   = $this->stamp();

        if ( ! $unknown ) {

            $this->sayIgnored( self::SETUP_ARGUMENTS );
            $this->write( CoreAPI::t( '--force: no module has tables without a recorded schema version, so nothing more is done.' ) );

            return $this->checkAndRepair();
        }

        if ( $unknown[0] === 'base' ) {

            $moved = $im->moveAsideForNewInstall( $stamp );
            $this->sayMoved( $moved['moved'] );

            if ( $moved['problem'] !== '' ) {

                return $this->fail( $moved['problem'], true );
            }

            $this->setUp();

            if ( $this->getCliOutcome()['outcome'] === 'failed' ) {

                return null;
            }

            return $this->reinstall( $moved['modules'], $stamp );
        }

        $this->sayIgnored( self::SETUP_ARGUMENTS );

        if ( $this->reinstall( $unknown, $stamp ) === false ) {

            return null;
        }

        return $this->checkAndRepair();
    }

    /**
     * Each of $modules moved aside and installed fresh (InstallManager::reinstallModule()), each step said.
     *
     * @param  list<string> $modules
     * @param  string       $stamp yyyymmddHHMMSS
     * @return false|null false when one failed, and the run with it
     */
    private function reinstall( array $modules, $stamp ) {

        foreach ( $modules as $name ) {

            $result = $this->installManager()->reinstallModule( $name, $stamp );
            $this->sayMoved( $result['moved'] );

            if ( $result['problem'] !== '' ) {

                $this->fail( $result['problem'] );

                return false;
            }

            $this->write( sprintf( CoreAPI::t( 'Module %s: installed fresh' ), $name ) );
        }

        return null;
    }

    /**
     * Each table renamed, said.
     *
     * @param  array<string, string> $moved old name => new name
     * @return void
     */
    private function sayMoved( array $moved ) {

        foreach ( $moved as $from => $to ) {

            $this->write( sprintf( CoreAPI::t( 'Moved aside: %1$s is now %2$s' ), $from, $to ) );
        }
    }

    /**
     * The yyyymmddHHMMSS the tables moved aside are named with. A method so a test can answer for it.
     *
     * @return string
     */
    protected function stamp() {

        return date( 'YmdHis' );
    }

    /**
     * What is wrong with the arguments together, whatever the installation's
     * state; null when nothing is.
     *
     * @return string|null
     */
    private function combinationProblem() {

        if ( $this->given( 'version' ) && $this->given( 'file' ) ) {

            return CoreAPI::t( 'Give --version or --file, not both.' );
        }

        if ( $this->given( 'sig' ) && ! $this->given( 'file' ) ) {

            return CoreAPI::t( '--sig goes with --file.' );
        }

        return null;
    }

    /**
     * A new installation: OWA_SECRET in owa-config.php
     * (InstallManager::writeInstanceSecret()), the schema, then the first
     * admin, the first site and the install-complete flag together
     * (InstallManager::finishInstall()), then the data directory's
     * permissions. A run that fails part way can be run again. A generated
     * password is printed last, once, and never logged.
     *
     * @return null
     */
    private function setUp() {

        $im      = $this->installManager();
        $user_id = (string) $this->param( 'user_id' );

        CoreAPI::notice( CoreAPI::t( 'Starting OWA Install from command line.' ) );

        if ( ! $this->c->isConfigFilePresent() ) {

            return $this->fail( CoreAPI::t( 'Could not locate config file. Aborting installation.' ), true );
        }

        $this->c->applyConfigConstants();

        // Before anything is created: an install that cannot have one stops here.
        $secret = $im->writeInstanceSecret();

        if ( $secret['problem'] !== '' ) {

            return $this->fail( $secret['problem'], true );
        }

        $this->write( $secret['lines'] );

        if ( ! $this->param( 'force' ) && in_array( 'base', $im->unknownStateModules(), true ) ) {

            return $this->fail( \OWA\Core\Module::unknownStateMessage( 'base' ), true );
        }

        if ( $im->installSchema() !== true ) {

            return $this->fail( CoreAPI::t( 'The database schema was not installed. The error log says why. Fix it, then run this command again.' ) );
        }

        // An admin an earlier run left is kept, with its own password: createAdminUser() returns nothing then.
        $created = null;

        $site_id = $im->finishInstall( function () use ( $im, $user_id, &$created ) {

            $created = $im->createAdminUser( $user_id, $this->param( 'email_address' ), $this->adminPassword() );

            return $im->createDefaultSite(
                (string) $this->param( 'domain' ),
                (string) $this->param( 'domain' ),
                (string) $this->param( 'description' ),
                (string) $this->param( 'site_family' )
            );
        } );

        if ( ! $site_id ) {

            return $this->fail( CoreAPI::t( 'The install did not complete, and nothing was saved. Check the error log, then run this command again.' ) );
        }

        $im->announceInstallComplete( $user_id, $site_id );

        CoreAPI::notice( CoreAPI::t( 'Install Completed.' ) );

        // Without the scheduler no report shows anything: say so where
        // the install is run, as the web installer's last page does.
        CoreAPI::notice( CoreAPI::t( 'Add this to the crontab of the user that owns the OWA files, or reports stay empty:' ) . ' '
            . \OWA\Module\Base\Classes\SchedulerHealth::cronLine() );
        CoreAPI::notice( CoreAPI::t( 'Then check it with: php cli.php cmd=schedule-status' ) );

        // Automatic updates write OWA's code as the cron user, usually
        // this one: say now if it cannot, not at the first release.
        $root = \OWA\Core\Env::root();

        if ( \OWA\Module\Base\Classes\AutoUpdate\Vcs::checkout( $root ) === null ) {

            $dirs = \OWA\Module\Base\Classes\AutoUpdate\Preflight::unwritableCode( $root, [ \OWA\Core\Env::dataDir() ] );

            if ( $dirs ) {

                CoreAPI::notice( sprintf( CoreAPI::t( 'Automatic updates run as the cron user and write OWA\'s code. This user cannot write %1$d of its directories, such as %2$s. Give them to the user the cron entry runs as.' ),
                    count( $dirs ), $dirs[0] === '' ? CoreAPI::t( 'the OWA directory' ) : $dirs[0] . '/' ) );
            }

            foreach ( \OWA\Module\Base\Classes\AutoUpdate\Preflight::temp( 0 ) as $problem ) {

                CoreAPI::notice( $problem );
            }
        }

        foreach ( $im->secureDataDir( (string) $this->param( 'web-group' ) ) as $said ) {

            CoreAPI::notice( $said );
        }

        if ( ! $created ) {

            $this->write( sprintf( CoreAPI::t( 'Admin "%s": already exists, not changed' ), (string) $im->adminUserId() ) );

        } elseif ( $this->generated ) {

            // Standard output only: notice() writes the log too.
            $this->write( sprintf( CoreAPI::t( 'Admin password for "%1$s": %2$s  Save it now; it is not shown again.' ), $user_id, $this->password ) );
        }

        return null;
    }

    /**
     * An installed system, no release named, in this order: the permissions
     * (InstallManager::secureDataDir()), the missing tables
     * (InstallManager::createMissingTables()), the pending schema updates
     * (cmd=core-update-schema, run here) and the stale tracking bundles
     * (cmd=publish-trackers, run here), each step's outcome said. A step that
     * fails stops the run. The admin and the sites are not changed. A module
     * with tables but no recorded schema version stops it before the first.
     *
     * @return null
     */
    private function checkAndRepair() {

        $im      = $this->installManager();
        $unknown = $im->unknownStateModules();

        if ( $unknown ) {

            return $this->fail( implode( ' ', array_map( [ \OWA\Core\Module::class, 'unknownStateMessage' ], $unknown ) ), true );
        }

        $changed = $im->secureDataDir( (string) $this->param( 'web-group' ) );

        $this->write( $changed
            ? array_map( static fn ( $said ) => CoreAPI::t( 'Permissions:' ) . ' ' . $said, $changed )
            : [ CoreAPI::t( 'Permissions: nothing to change' ) ] );

        $tables = $im->createMissingTables();

        if ( $tables['problem'] !== null ) {

            return $this->fail( CoreAPI::t( 'Tables:' ) . ' ' . $tables['problem'], true );
        }

        if ( $tables['failed'] ) {

            return $this->fail( sprintf( CoreAPI::t( 'Tables: %s could not be created. The error log says why.' ), implode( ', ', $tables['failed'] ) ) );
        }

        $this->write( $tables['created']
            ? sprintf( CoreAPI::t( 'Tables: created %s' ), implode( ', ', $tables['created'] ) )
            : CoreAPI::t( 'Tables: none missing' ) );

        $pending = $this->updatesPending();
        $updates = $this->runCommand( CoreUpdateSchemaCli::class );

        if ( $updates['outcome'] !== 'ok' ) {

            return $this->fail( sprintf( CoreAPI::t( 'Schema updates: update %1$s did not apply. %2$s' ), $this->nextUpdate(), $updates['message'] ) );
        }

        $this->write( $pending ? CoreAPI::t( 'Schema updates: applied' ) : CoreAPI::t( 'Schema updates: none pending' ) );

        $publish = $this->runCommand( TrackerPublishCli::class );

        if ( $publish['outcome'] !== 'ok' ) {

            return $this->fail( CoreAPI::t( 'Tracking bundles:' ) . ' ' . $publish['message'] );
        }

        $admin = $im->adminUserId();

        $this->write( [
            CoreAPI::t( 'Tracking bundles:' ) . ' ' . ( $publish['message'] !== '' ? $publish['message'] : CoreAPI::t( 'published' ) ),
            $admin !== null
                ? sprintf( CoreAPI::t( 'Admin "%s": already exists, not changed' ), $admin )
                : CoreAPI::t( 'Admin: there is none, and none was created' ),
            $im->hasSite()
                ? CoreAPI::t( 'Site: already exists, not changed' )
                : CoreAPI::t( 'Site: there is none, and none was created; add one with cmd=add-site' ),
        ] );

        return null;
    }

    /**
     * A CLI command run in this process, as cli.php runs it
     * (AutoUpdate\Finish::command()), and how it ended. A method so a test
     * can answer for it.
     *
     * @param  class-string $class
     * @return array{outcome: string, message: string, final: bool}
     */
    protected function runCommand( $class ) {

        return \OWA\Module\Base\Classes\AutoUpdate\Finish::command( $class );
    }

    /**
     * Whether a module has an update pending (CoreAPI::getModulesNeedingUpdates()).
     * A method so a test can answer for it.
     *
     * @return bool
     */
    protected function updatesPending() {

        return (bool) CoreAPI::getModulesNeedingUpdates();
    }

    /**
     * The update a stopped run of the schema updates stopped at:
     * <module>.<sequence>, the first pending one of the first module behind.
     * A method so a test can answer for it.
     *
     * @return string
     */
    protected function nextUpdate() {

        $s = \OWA\Core\CoreAPI::serviceSingleton();

        foreach ( $s->getModulesNeedingUpdates() as $name ) {

            $module   = $s->modules[ $name ] ?? null;
            $recorded = (int) CoreAPI::getSetting( $name, 'schema_version' );

            foreach ( \OWA\Core\Module::updateClasses( $name ) as $seq => $class ) {

                if ( $seq > $recorded && ( ! $module || $seq <= $module->getRequiredSchemaVersion() ) ) {

                    return $name . '.' . $seq;
                }
            }
        }

        return CoreAPI::t( '(unknown)' );
    }

    /**
     * An installed system: the release --version or --file names, installed
     * (Install::byHand(), Install::fromFile()).
     *
     * @return null
     */
    private function installRelease() {

        $force  = (bool) $this->param( 'force' );
        $result = $this->given( 'file' )
            ? $this->installFile( (string) $this->param( 'file' ), $this->given( 'sig' ) ? (string) $this->param( 'sig' ) : null, $force )
            : $this->install( (string) $this->param( 'version' ), $force );

        $this->write( array_merge( [ $result['message'] ], array_map( static fn ( $l ) => '  ' . $l, $result['lines'] ) ) );

        if ( $result['outcome'] !== Install::OK ) {

            return $this->fail( $result['message'], ! in_array( $result['outcome'], self::RETRIED, true ) );
        }

        return null;
    }

    /**
     * Those of $names given to an installed system, said to be ignored.
     *
     * @param  list<string> $names
     * @return void
     */
    private function sayIgnored( array $names ) {

        $given = array_values( array_filter( $names, fn ( $n ) => $this->given( $n ) ) );

        if ( $given ) {

            CoreAPI::notice( sprintf( CoreAPI::t( 'OWA is already installed, so these arguments are ignored: %s.' ), implode( ', ', $given ) ) );
        }
    }

    /**
     * Whether OWA is installed (InstallManager::isInstalled()), read once.
     *
     * @return bool
     */
    private function isInstalled() {

        return $this->installed ??= (bool) $this->installManager()->isInstalled();
    }

    /** @return bool whether --version or --file names a release */
    private function releaseNamed() {

        return $this->given( 'version' ) || $this->given( 'file' );
    }

    /**
     * Whether an argument was given on the command line, by its name.
     *
     * @param  string $name
     * @return bool
     */
    private function given( $name ) {

        return array_key_exists( $name, (array) $this->params );
    }

    /**
     * The first admin's password, made once: one line read from standard
     * input with --password-stdin, else generated (InstallManager::generatePassword()).
     *
     * @return string
     */
    private function adminPassword() {

        if ( $this->password === null ) {

            $this->generated = ! $this->param( 'password-stdin' );
            $this->password  = $this->generated ? InstallManager::generatePassword() : $this->readStdin();
        }

        return $this->password;
    }

    /**
     * One line of standard input (firstLine()). A method so a test can
     * answer for it.
     *
     * @return string
     */
    protected function readStdin() {

        return self::firstLine( defined( 'STDIN' ) ? STDIN : fopen( 'php://stdin', 'r' ) );
    }

    /**
     * The first line of $in without its line ending, \n or \r\n; '' when
     * there is none.
     *
     * @param  resource|false $in
     * @return string
     */
    public static function firstLine( $in ) {

        $line = is_resource( $in ) ? fgets( $in ) : false;

        return $line === false ? '' : (string) preg_replace( '/\r?\n$/', '', $line );
    }

    /**
     * What the install is done through. A method so a test can answer for it.
     *
     * @return InstallManager
     */
    protected function installManager() {

        return $this->install_manager ??= new InstallManager();
    }

    /**
     * A method so a test can answer for it.
     *
     * @param  string $version
     * @param  bool   $force
     * @return array{outcome: string, message: string, lines: list<string>}
     */
    protected function install( $version, $force ) {

        return Install::byHand( $version, $force );
    }

    /**
     * A method so a test can answer for it.
     *
     * @param  string      $file
     * @param  string|null $sig
     * @param  bool        $force
     * @return array{outcome: string, message: string, lines: list<string>}
     */
    protected function installFile( $file, $sig, $force ) {

        return Install::fromFile( $file, $sig, $force );
    }
}
