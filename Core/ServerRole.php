<?php

namespace OWA\Core;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * What this server does: OWA_SERVER_ROLE in owa-config.php, the server_role
 * setting. A role is the set of request types (RequestType) it serves, and
 * whether it runs the scheduler:
 *
 *   full       tracking, ui, rest, cli, install; scheduler. The default.
 *   collector  tracking, and the CLI commands marked for it. No database:
 *              stored settings are not read, every beacon is queued, and a
 *              queue backend other than the file queue is required.
 *   worker     cli, every command; scheduler.
 *   console    ui, and the CLI commands marked for it.
 *   api        rest, and the CLI commands marked for it.
 *
 * A comma list combines roles ('console,api'); full is all of them. Every
 * role but collector needs the database.
 *
 * Boot checks the role against the request (bootProblem()): a request type the
 * role does not serve, an unknown role, or a configuration the role cannot run
 * on is refused with one log line (refuse()).
 *
 * A CLI command names the roles it runs under (Controller\Cli::serverRoles(),
 * by default full and worker); cli.php refuses one this role is not among.
 */
final class ServerRole {

    const FULL      = 'full';
    const COLLECTOR = 'collector';
    const WORKER    = 'worker';
    const CONSOLE   = 'console';
    const API       = 'api';

    const ALL = array( self::FULL, self::COLLECTOR, self::WORKER, self::CONSOLE, self::API );

    /**
     * The request types each role serves. cli on collector, console and api
     * is limited to the commands marked for that role.
     */
    const SERVES = array(
        self::FULL      => RequestType::ALL,
        self::COLLECTOR => array( RequestType::TRACKING, RequestType::CLI ),
        self::WORKER    => array( RequestType::CLI ),
        self::CONSOLE   => array( RequestType::UI, RequestType::CLI ),
        self::API       => array( RequestType::REST, RequestType::CLI ),
    );

    /** The roles that run the scheduler. */
    const SCHEDULER = array( self::FULL, self::WORKER );

    /** The roles without a database. */
    const WITHOUT_DATABASE = array( self::COLLECTOR );

    /** What a role with a database needs in owa-config.php. */
    const DATABASE_CONSTANTS = array(
        'OWA_DB_NAME', 'OWA_DB_HOST', 'OWA_DB_USER', 'OWA_DB_PASSWORD',
        'OWA_NONCE_KEY', 'OWA_NONCE_SALT', 'OWA_AUTH_KEY', 'OWA_AUTH_SALT',
    );

    /**
     * Constants that are no longer read, and what replaced them. The 1.x
     * names still have an effect (Settings::applyConfigConstants()); the
     * others were never released.
     */
    const RETIRED_CONSTANTS = array(
        'OWA_QUEUE_EVENTS'              => 'The 1.x name of OWA_QUEUE_TRACKING_EVENTS, still read while that is not set. Rename it.',
        'OWA_USE_STATIC_CONFIG_ONLY'    => 'The 1.x switch, still read: stored settings are not read at boot. Set OWA_SERVER_ROLE instead, collector for a server that only receives beacons.',
        'OWA_QUEUE_TRACKER_INGEST'      => 'No longer read. Use OWA_QUEUE_TRACKING_EVENTS.',
        'OWA_TRACKER_INGEST_QUEUE_TYPE' => 'No longer read. The queue backend follows from the queue module\'s constant, such as OWA_SQS_QUEUE_URL.',
        'OWA_TRACKER_INGEST_DRAIN'      => 'No longer read. The servers whose role runs the scheduler drain the queue.',
        'OWA_SCHEDULER_ENABLED'         => 'No longer read. The role decides: full and worker run the scheduler.',
        'OWA_ACTIVE_MODULES'            => 'No longer read. A module with a constant of its own is active while it is set, such as OWA_SQS_QUEUE_URL.',
    );

    /** @var string[] */
    private $roles = array();

    /** @var string[] names that are not roles */
    private $unknown = array();

    /**
     * @param string|null $value a role, or a comma list of them; empty is full
     */
    public function __construct( $value ) {

        foreach ( explode( ',', strtolower( (string) $value ) ) as $name ) {

            $name = trim( $name );

            if ( $name === '' ) {

                continue;
            }

            if ( in_array( $name, self::ALL, true ) ) {

                $this->roles[ $name ] = $name;

            } else {

                $this->unknown[ $name ] = $name;
            }
        }

        if ( ! $this->roles || isset( $this->roles[ self::FULL ] ) ) {

            $this->roles = array( self::FULL => self::FULL );
        }

        $this->roles   = array_values( $this->roles );
        $this->unknown = array_values( $this->unknown );
    }

    /**
     * This server's role.
     *
     * @return self
     */
    public static function current() {

        return new self( CoreAPI::getSetting( 'base', 'server_role' ) );
    }

    /** @return string[] */
    public function roles() {

        return $this->roles;
    }

    /** @return string[] what OWA_SERVER_ROLE named that is not a role */
    public function unknown() {

        return $this->unknown;
    }

    /** @return string the roles, as OWA_SERVER_ROLE would name them */
    public function name() {

        return implode( ',', $this->roles );
    }

    /** @return bool */
    public function is( $role ) {

        return in_array( $role, $this->roles, true );
    }

    /** @return string[] the request types served, in RequestType::ALL order */
    public function requestTypes() {

        $types = array();

        foreach ( $this->roles as $role ) {

            $types = array_merge( $types, self::SERVES[ $role ] );
        }

        return array_values( array_intersect( RequestType::ALL, $types ) );
    }

    /** @return bool */
    public function serves( $request_type ) {

        return in_array( (string) $request_type, $this->requestTypes(), true );
    }

    /** @return bool */
    public function runsScheduler() {

        return (bool) array_intersect( $this->roles, self::SCHEDULER );
    }

    /** @return bool */
    public function hasDatabase() {

        return (bool) array_diff( $this->roles, self::WITHOUT_DATABASE );
    }

    /**
     * Whether every beacon is queued whatever OWA_QUEUE_TRACKING_EVENTS says:
     * a collector has no database to write one to.
     *
     * @return bool
     */
    public function queuesTrackingEvents() {

        return $this->is( self::COLLECTOR );
    }

    /**
     * Whether a CLI command marked for these roles may run here. full runs
     * every command.
     *
     * @param string[] $command_roles
     * @return bool
     */
    public function mayRun( array $command_roles ) {

        return $this->is( self::FULL ) || (bool) array_intersect( $this->roles, $command_roles );
    }

    /**
     * Whether stored settings are read at boot: not on a server without a
     * database, nor under the 1.x OWA_USE_STATIC_CONFIG_ONLY.
     *
     * @return bool
     */
    public static function readsStoredSettings() {

        return self::current()->hasDatabase()
            && ! CoreAPI::getSetting( 'base', 'skip_stored_settings' );
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    /**
     * The OWA_ constants defined in this process, name => value.
     *
     * @return array
     */
    public static function definedConstants() {

        $out = array();

        foreach ( (array) ( get_defined_constants( true )['user'] ?? array() ) as $name => $value ) {

            if ( strpos( $name, 'OWA_' ) === 0 ) {

                $out[ $name ] = $value;
            }
        }

        return $out;
    }

    /**
     * What stops this role running on a configuration.
     *
     * @param array $defined  the OWA_ constants defined, name => value
     * @param array $backends the queue backends configured, type => constant
     *                        (ModuleActivation::queueBackends())
     * @param array $backend_constants every constant that would configure one
     * @return string[] one sentence per problem
     */
    public function configProblems( array $defined, array $backends, array $backend_constants = array() ) {

        $problems = array();

        if ( count( $backends ) > 1 ) {

            $problems[] = sprintf( 'Two queue backends are configured (%s): set one.',
                implode( ', ', array_values( $backends ) ) );
        }

        if ( $this->is( self::COLLECTOR ) && ! $backends ) {

            $problems[] = sprintf( 'Role collector needs a queue backend to send beacons to: set %s.',
                $backend_constants ? implode( ' or ', $backend_constants ) : 'a queue module\'s constant' );
        }

        if ( $this->hasDatabase() ) {

            $missing = array_values( array_diff( self::DATABASE_CONSTANTS, array_keys( $defined ) ) );

            if ( $missing ) {

                $problems[] = sprintf( 'Role %s needs %s in owa-config.php.', $this->name(), implode( ', ', $missing ) );
            }
        }

        return $problems;
    }

    /**
     * Why this request must be refused at boot, or null.
     *
     * Checked before stored settings are read. The configuration is only
     * checked once owa-config.php exists and outside the installer, which
     * writes it.
     *
     * @param string     $request_type
     * @param array|null $defined  the OWA_ constants; this process's when null
     * @param bool|null  $configured whether owa-config.php exists; this install's when null
     * @return array|null status (HTTP), message (for the log and the CLI)
     */
    public static function bootProblem( $request_type, $defined = null, $configured = null ) {

        $role = self::current();

        if ( $role->unknown() ) {

            return array( 'status' => 503, 'message' => sprintf(
                'OWA_SERVER_ROLE names %s, which %s not a role. The roles are %s.',
                implode( ', ', $role->unknown() ), count( $role->unknown() ) === 1 ? 'is' : 'are',
                implode( ', ', self::ALL ) ) );
        }

        if ( ! $role->serves( $request_type ) ) {

            return array( 'status' => 404, 'message' => sprintf(
                'This server\'s role is %s, which serves %s, not %s requests.',
                $role->name(), implode( ', ', $role->requestTypes() ), $request_type ) );
        }

        $configured = $configured ?? CoreAPI::configSingleton()->isConfigFilePresent();

        if ( ! $configured || defined( 'OWA_INSTALLING' ) ) {

            return null;
        }

        $problems = $role->configProblems(
            $defined ?? self::definedConstants(),
            ModuleActivation::queueBackends( $defined ),
            ModuleActivation::queueBackendConstants() );

        return $problems ? array( 'status' => 503, 'message' => implode( ' ', $problems ) ) : null;
    }

    /**
     * Refuse the request: one line in the error log, then a short answer in
     * the request's own form, and exit. log.php has already answered its
     * beacon by the time OWA boots, so a tracking request only logs.
     *
     * @param string $request_type
     * @param array  $problem from bootProblem()
     * @return never
     */
    public static function refuse( $request_type, array $problem ) {

        CoreAPI::error( sprintf( 'Refused a %s request: %s', $request_type, $problem['message'] ) );

        if ( $request_type === RequestType::CLI ) {

            fwrite( defined( 'STDERR' ) ? STDERR : fopen( 'php://stderr', 'w' ), 'OWA: ' . $problem['message'] . PHP_EOL );

            exit( 1 );
        }

        if ( $request_type !== RequestType::TRACKING && ! headers_sent() ) {

            http_response_code( (int) $problem['status'] );
            header( 'Content-Type: text/plain; charset=utf-8' );
            header( 'Cache-Control: no-store' );

            echo (int) $problem['status'] === 404
                ? CoreAPI::t( 'This server does not serve this page.' )
                : CoreAPI::t( 'This server is not configured correctly. Its error log says what is missing.' );
        }

        exit;
    }

    /**
     * Constants in owa-config.php that are retired, and what to do about each.
     *
     * @param array|null $defined the OWA_ constants; this process's when null
     * @return array<string,string> constant => what to do
     */
    public static function upgradeNotices( $defined = null ) {

        return array_intersect_key( self::RETIRED_CONSTANTS, $defined ?? self::definedConstants() );
    }

    // ---------------------------------------------------------------------
    // CLI commands
    // ---------------------------------------------------------------------

    /**
     * The roles a CLI action runs under: its controller's serverRoles(), or
     * the default for a controller that declares none.
     *
     * @param string $action a registered action name, such as base.instanceInfoCli
     * @return string[]|null null when the action resolves to no CLI controller
     */
    public static function commandRoles( $action ) {

        $map   = CoreAPI::serviceSingleton()->getMapValue( 'actions', (string) $action );
        $class = is_array( $map ) ? (string) ( $map['class_name'] ?? '' ) : '';

        if ( $class === '' || ! class_exists( $class ) ) {

            return null;
        }

        // A command whose controller is not a Controller\Cli (sitesAddCli
        // extends its screen) declares its roles the same way, or has the default.
        return method_exists( $class, 'serverRoles' )
            ? (array) $class::serverRoles()
            : Controller\Cli::serverRoles();
    }

    /**
     * Why this server must not run a CLI command, or null.
     *
     * @param string $command the name typed after cmd=
     * @param string $action  the action it resolves to
     * @return string|null
     */
    public function commandProblem( $command, $action ) {

        $roles = self::commandRoles( $action );

        if ( $roles === null || $this->mayRun( $roles ) ) {

            return null;
        }

        return sprintf( 'cmd=%s does not run on this server: its role is %s, and the command runs on %s.',
            $command, $this->name(), implode( ', ', $roles ) );
    }
}
