<?php

namespace OWA\Core;

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
 *
 * @phpstan-type BootProblem array{status: int, message: string, php_log?: bool}
 */
final class ServerRole {

    const FULL      = 'full';
    const COLLECTOR = 'collector';
    const WORKER    = 'worker';
    const CONSOLE   = 'console';
    const API       = 'api';

    const ALL = [ self::FULL, self::COLLECTOR, self::WORKER, self::CONSOLE, self::API ];

    /**
     * The request types each role serves. cli on collector, console and api
     * is limited to the commands marked for that role.
     */
    const SERVES = [
        self::FULL      => RequestType::ALL,
        self::COLLECTOR => [ RequestType::TRACKING, RequestType::CLI ],
        self::WORKER    => [ RequestType::CLI ],
        self::CONSOLE   => [ RequestType::UI, RequestType::CLI ],
        self::API       => [ RequestType::REST, RequestType::CLI ],
    ];

    /** The roles that run the scheduler. */
    const SCHEDULER = [ self::FULL, self::WORKER ];

    /** The roles without a database. */
    const WITHOUT_DATABASE = [ self::COLLECTOR ];

    /*
     * What owa-config.php is held to -- the constants a role with a database
     * needs, the secrets' rules, the retired constants -- is
     * Core\Requirements' (DATABASE_CONSTANTS, URL_CONSTANTS, SECRET_*,
     * RETIRED_CONSTANTS). This class applies it to a role.
     */

    /** @var string[] */
    private $roles = [];

    /** @var string[] names that are not roles */
    private $unknown = [];

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

            $this->roles = [ self::FULL => self::FULL ];
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

    /**
     * @param  string $role one of the role constants
     * @return bool
     */
    public function is( $role ) {

        return in_array( $role, $this->roles, true );
    }

    /** @return string[] the request types served, in RequestType::ALL order */
    public function requestTypes() {

        $types = [];

        foreach ( $this->roles as $role ) {

            $types = array_merge( $types, self::SERVES[ $role ] );
        }

        return array_values( array_intersect( RequestType::ALL, $types ) );
    }

    /**
     * @param  string $request_type one of RequestType::ALL
     * @return bool
     */
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
     * Whether stored settings are read at boot: on every role with a
     * database. The 1.x OWA_USE_STATIC_CONFIG_ONLY is not read; a collector,
     * the role it described, reads none.
     *
     * @return bool
     */
    public static function readsStoredSettings() {

        return self::current()->hasDatabase();
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    /**
     * The OWA_ constants defined in this process, name => value.
     *
     * @return array<string, mixed> name => value, whatever type it was defined with
     */
    public static function definedConstants() {

        $out = [];

        foreach ( (array) ( get_defined_constants( true )['user'] ?? [] ) as $name => $value ) {

            if ( strpos( $name, 'OWA_' ) === 0 ) {

                $out[ $name ] = $value;
            }
        }

        return $out;
    }

    /**
     * What stops this role running on a configuration.
     *
     * @param array<string, mixed>  $defined  the OWA_ constants defined, name => value
     * @param array<string, string> $backends the queue backends configured, type => constant
     *                                        (ModuleActivation::queueBackends())
     * @param list<mixed> $backend_constants every constant that would configure one
     *                                       (ModuleActivation::queueBackendConstants())
     * @return list<string> one sentence per problem
     */
    public function configProblems( array $defined, array $backends, array $backend_constants = [] ) {

        $problems = [];

        if ( count( $backends ) > 1 ) {

            $problems[] = sprintf( 'Two queue backends are configured (%s): set one.',
                implode( ', ', array_values( $backends ) ) );
        }

        if ( $this->is( self::COLLECTOR ) && ! $backends ) {

            $problems[] = sprintf( 'Role collector needs a queue backend to send beacons to: set %s.',
                $backend_constants ? implode( ' or ', $backend_constants ) : 'a queue module\'s constant' );
        }

        if ( $this->hasDatabase() ) {

            $missing = array_values( array_diff( Requirements::DATABASE_CONSTANTS, array_keys( $defined ) ) );

            foreach ( Requirements::URL_CONSTANTS as $constant ) {

                if ( (string) ( $defined[ $constant ] ?? '' ) === '' ) {

                    $missing[] = $constant;
                }
            }

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
     * @param array<string, mixed>|null $defined  the OWA_ constants; this process's when null
     * @param bool|null  $configured whether owa-config.php exists; this install's when null
     * @param bool|null  $installing whether the installer is running (OWA_INSTALLING); this process's when null
     * @param list<string>|null $config_problems what stops OWA in owa-config.php (Settings::configProblems()); this install's when null
     * @return BootProblem|null status (HTTP), message (for the log and the CLI)
     */
    public static function bootProblem( $request_type, $defined = null, $configured = null, $installing = null, $config_problems = null ) {

        /*
         * First, and for every request type and the installer too: a config
         * file that uses $this was not read, so nothing after this has its
         * constants. log.php has already answered its beacon; the beacon is
         * dropped, as the queue it would go to is named in the file that was
         * not read.
         */
        $config_problems = $config_problems ?? CoreAPI::configSingleton()->configProblems();

        if ( $config_problems ) {

            // php_log: OWA's own log is named from constants the file did not define.
            return [ 'status' => 503, 'message' => implode( ' ', $config_problems ), 'php_log' => true ];
        }

        $role = self::current();

        if ( $role->unknown() ) {

            return [ 'status' => 503, 'message' => sprintf(
                'OWA_SERVER_ROLE names %s, which %s not a role. The roles are %s.',
                implode( ', ', $role->unknown() ), count( $role->unknown() ) === 1 ? 'is' : 'are',
                implode( ', ', self::ALL ) ) ];
        }

        if ( ! $role->serves( $request_type ) ) {

            return [ 'status' => 404, 'message' => sprintf(
                'This server\'s role is %s, which serves %s, not %s requests.',
                $role->name(), implode( ', ', $role->requestTypes() ), $request_type ) ];
        }

        $configured = $configured ?? CoreAPI::configSingleton()->isConfigFilePresent();
        $installing = $installing ?? defined( 'OWA_INSTALLING' );

        if ( ! $configured || $installing ) {

            return null;
        }

        $problems = $role->configProblems(
            $defined ?? self::definedConstants(),
            ModuleActivation::queueBackends( $defined ),
            ModuleActivation::queueBackendConstants() );

        if ( $problems ) {

            return [ 'status' => 503, 'message' => implode( ' ', $problems ) ];
        }

        /*
         * Weak secrets refuse every request but the command line, where
         * cmd=reset-secrets writes new ones; nothing there is signed for a
         * browser. Every other command warns (secretsWarning()).
         */
        $message = $request_type !== RequestType::CLI ? $role->secretsProblem( $defined ?? self::definedConstants() ) : null;

        return $message !== null ? [ 'status' => 503, 'message' => $message ] : null;
    }

    /** The command that writes new secrets, which secretsWarning() leaves quiet. */
    const RESET_SECRETS_COMMAND = 'reset-secrets';

    /**
     * Why this role's secrets are unusable (weakSecrets()), or null. A role
     * without a database signs nothing, so its are never asked about.
     *
     * @param array<string, mixed> $defined the OWA_ constants
     * @return string|null
     */
    public function secretsProblem( array $defined ) {

        $weak = $this->hasDatabase() ? self::weakSecrets( $defined ) : [];

        if ( ! $weak ) {

            return null;
        }

        return sprintf(
            CoreAPI::t( '%s in owa-config.php must be a random string of at least %d characters, not empty and not the example value. php cli.php cmd=reset-secrets writes new ones.' ),
            implode( ', ', $weak ), Requirements::SECRET_MIN_LENGTH );
    }

    /**
     * The warning cli.php writes to stderr before a command when this role's
     * secrets are unusable, so a scheduler running on them logs it; null for
     * none, and for cmd=reset-secrets.
     *
     * @param string                    $command the cmd= it runs
     * @param array<string, mixed>|null $defined the OWA_ constants; this process's when null
     * @return string|null
     */
    public static function secretsWarning( $command, $defined = null ) {

        if ( (string) $command === self::RESET_SECRETS_COMMAND ) {

            return null;
        }

        $problem = self::current()->secretsProblem( $defined ?? self::definedConstants() );

        return $problem !== null ? sprintf( CoreAPI::t( 'Warning: %s' ), $problem ) : null;
    }

    /**
     * The secrets (SECRET_CONSTANTS) that are defined but empty, shorter than
     * SECRET_MIN_LENGTH or an example value. One not defined is
     * configProblems()' to name.
     *
     * @param array<string, mixed> $defined the OWA_ constants
     * @return list<string> their names
     */
    public static function weakSecrets( array $defined ) {

        $weak = [];

        foreach ( Requirements::SECRET_CONSTANTS as $constant ) {

            if ( ! array_key_exists( $constant, $defined ) ) {

                continue;
            }

            $value = is_scalar( $defined[ $constant ] ) ? (string) $defined[ $constant ] : '';

            if ( strlen( $value ) < Requirements::SECRET_MIN_LENGTH || in_array( $value, Requirements::SECRET_PLACEHOLDERS, true ) ) {

                $weak[] = $constant;
            }
        }

        return $weak;
    }

    /**
     * Refuse the request: one line in the error log, then a short answer in
     * the request's own form, and exit. log.php has already answered its
     * beacon by the time OWA boots, so a tracking request only logs.
     *
     * @param string $request_type
     * @param BootProblem $problem from bootProblem()
     * @return never
     */
    public static function refuse( $request_type, array $problem ) {

        CoreAPI::error( sprintf( 'Refused a request of type %s: %s', $request_type, $problem['message'] ) );

        if ( ! empty( $problem['php_log'] ) && $request_type !== RequestType::CLI ) {

            error_log( 'OWA: ' . $problem['message'] );
        }

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
     * @param array<string, mixed>|null $defined the OWA_ constants; this process's when null
     * @return array<string,string> constant => what to do
     */
    public static function upgradeNotices( $defined = null ) {

        return array_intersect_key( Requirements::RETIRED_CONSTANTS, $defined ?? self::definedConstants() );
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

        // A command's controller is a Controller\Cli (CliCommandsTest); one
        // without serverRoles() has the default.
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
