<?php
namespace OWA\Core\Controller;

/**
 * CLI Controller Class
 *
 * This controller should be used for internal management pages/actions that require authentication
 */


class Cli extends \OWA\Core\AdminController {

    public $is_admin = true;

    /** getJobLease() for a command that does not derive its own, in seconds. */
    const DEFAULT_JOB_LEASE = 21600;   // 6 hours

    /**
     * Constructor
     *
     * @param array<string, mixed> $params request params: name => value, as sent
     */
    public function __construct($params) {

        if ( \OWA\Core\RequestType::is( \OWA\Core\RequestType::CLI ) ) {

            parent::__construct($params);

        } else {

            \OWA\Core\CoreAPI::notice("Controller not called from CLI");
            exit;
        }
    }

    /**
     * The server roles this command runs under (Core\ServerRole); cli.php
     * refuses it on any other. full runs every command. A command a
     * collector or api server may run names that role.
     *
     * Static, like usage(): cli.php asks before the controller is built.
     *
     * @return string[]
     */
    public static function serverRoles() {

        return [ \OWA\Core\ServerRole::FULL ];
    }

    /**
     * Whether this command needs OWA booted: settings, the database, modules.
     *
     * Every command does but one that answers from the code alone, such as
     * cmd=version-info, which the build runs in a checkout with no
     * owa-config.php. cli.php asks before `new owa()` (Core\CliWithoutBoot),
     * and a command answering false runs as runWithoutBoot() instead: never
     * constructed, on any server role, with or without its module active.
     * Such a command must be declared in its module's config/cli_commands.php;
     * one registered in code is only found after boot.
     *
     * Static, like serverRoles(): asked before the controller is built.
     *
     * @return bool
     */
    public static function needsBoot() {

        return true;
    }

    /**
     * Run without OWA booted, for a command whose needsBoot() is false: the
     * parsed arguments in, plain output to STDOUT and STDERR, an exit code
     * out (0 done, 1 failed, 2 the command line is wrong).
     *
     * @param  array<string, mixed> $params the parsed command line, cmd included
     * @return int
     */
    public static function runWithoutBoot( array $params ) {

        fwrite( STDERR, sprintf( "cmd=%s needs OWA booted.\n", (string) ( $params['cmd'] ?? '' ) ) );

        return 1;
    }

    /**
     * What the command does and the arguments it reads.
     *
     * The wiki's CLI reference is generated from this
     * (tests/tools/wiki/sections/cli.php), so every command declares its own:
     * an inherited declaration would describe the parent. Static, because the
     * constructor exits outside a CLI request and the generator must not run
     * one to read it.
     *
     * `arguments` are the command's params(): each keyed by its name, with
     * the dashes it is written with for a switch (`--dry-run`), and declared
     * as Core\Params describes: its type, description, and any range,
     * options, required or default. The command reads each with param().
     *
     * `deprecated` => true marks a command kept only for scripts that still
     * call it. It still runs; the wiki's CLI reference leaves it out.
     *
     * @return array{description: string, arguments: array<string, array<string, mixed>>, deprecated?: bool}
     */
    public static function usage() {

        return [ 'description' => '', 'arguments' => [] ];
    }

    /**
     * usage()'s arguments.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function params() {

        return (array) ( static::usage()['arguments'] ?? [] );
    }

    /**
     * Refused arguments are a usage error, exiting 2: usageMessage().
     *
     * @param  array<string, string> $problems
     * @return mixed this controller's data
     */
    protected function paramsRefused( array $problems ) {

        $this->usageError( self::usageMessage( (string) ( $this->params['cmd'] ?? self::commandFor( static::class ) ), $problems ) );

        return $this->data;
    }

    /**
     * Why $params are refused by $class's declared arguments, as one line;
     * null when they are not. For cli.php, which runs a command that needs no
     * boot without constructing it.
     *
     * @param  class-string<Cli>   $class
     * @param  array<mixed>        $params
     * @return string|null
     */
    public static function argumentProblem( $class, array $params ) {

        $problems = \OWA\Core\Params::problems( $class::params(), $params );

        return $problems ? self::usageMessage( (string) ( $params['cmd'] ?? '' ), $problems ) : null;
    }

    /**
     * The usage error for refused arguments: the command, then each problem,
     * which names its argument and what it takes.
     *
     * @param  string                $command
     * @param  array<string, string> $problems
     * @return string
     */
    public static function usageMessage( $command, array $problems ) {

        $problems = array_map( static fn ( $p ) => rtrim( $p, '.' ) . '.', array_values( $problems ) );

        return $command !== ''
            ? sprintf( \OWA\Core\CoreAPI::t( 'cmd=%1$s: %2$s Nothing was done.' ), $command, implode( ' ', $problems ) )
            : sprintf( \OWA\Core\CoreAPI::t( '%s Nothing was done.' ), implode( ' ', $problems ) );
    }

    /**
     * The command that runs $class, or '' when none is registered.
     *
     * @param  string $class
     * @return string
     */
    protected static function commandFor( $class ) {

        $s = \OWA\Core\CoreAPI::serviceSingleton();

        foreach ( (array) $s->getMap( 'cli_commands' ) as $command => $action ) {

            if ( ( $s->getMapValue( 'actions', $action )['class_name'] ?? null ) === $class ) {

                return (string) $command;
            }
        }

        return '';
    }

    /**
     * What this command actually did: 'ok', 'refused' or 'failed'.
     *
     * doAction() returns $this->data and CLI actions return void, so without this
     * a command that declined to act and one that did the work look identical to
     * a dispatcher, which has to decide whether an occurrence was satisfied and
     * record why.
     *
     * Defaults to 'ok': a command that never says otherwise ran to completion.
     * Commands call refuse() or fail() where they call notice().
     *
     * @var string
     */
    protected $cli_outcome = 'ok';

    /** @var string */
    protected $cli_message = '';

    /**
     * Whether this run's failure gave up: no attempts left, whatever the job's
     * retry policy says. fail( $msg, true ) and usageError() set it.
     *
     * @var bool
     */
    protected $cli_final = false;

    /** The exit code of a command that failed. */
    const EXIT_FAILED = 1;

    /** The exit code of a command given an argument it does not take, or a value it cannot read. */
    const EXIT_USAGE = 2;

    /** Arguments every command accepts, beside those its usage() declares. */
    const GLOBAL_ARGUMENTS = [ 'cmd' ];

    /** 1.x's names for commands 2.0 renamed, and their names now: cli.php says which, and runs nothing. */
    const RENAMED = [
        'update'            => 'core-update-schema',
        'update-geoip-db'   => 'geoip-update-db',
        'install-module'    => 'activate',
        'install'           => 'core-install',
    ];

    /** @var bool whether a failure of this command sets cli.php's exit code */
    protected $exit_on_failure = true;

    /** @var int the exit code cli.php ends with: the first failure's code, 0 when none. */
    public static $exit_code = 0;

    /**
     * The job lock held for this run, when the scheduler is running us.
     *
     * @var \OWA\Module\Base\Classes\JobLease|null
     */
    protected $job_lease = null;

    /**
     * Declined to act, on purpose. Not an error.
     *
     * The occurrence is still consumed -- a refusal is an answer about it, not a
     * failure to answer -- so the scheduler advances the slot and does not retry
     * every minute.
     *
     * Returns null so a caller can write `return $this->refuse( ... );` -- which
     * reads as the "say why, and stop" that it is, and keeps
     * Controller::finishActionCall() on its existing falsy path.
     *
     * @param string $msg
     * @return null
     */
    protected function refuse( $msg ) {

        // A refusal after a failure leaves the run failed.
        if ( $this->cli_outcome === 'ok' ) {

            $this->cli_outcome = 'refused';
        }

        $this->cli_message = $this->cli_message ?: $msg;

        \OWA\Core\CoreAPI::notice( $msg );

        return null;
    }

    /**
     * Tried and failed. A job is retried as its retry policy says
     * (Classes\RetryPolicy).
     *
     * Db::query() swallows SQL errors and
     * returns falsy, so without commands calling this, a job whose ALTER TABLE
     * was rejected by the server would record 'ok' indefinitely.
     *
     * $final GIVES UP: the run has no attempts left, whatever the policy
     * says. A recurring job waits for its next run, a one-off job is failed.
     * Pass it only for a failure the command can name, that a retry would
     * meet again -- a rejected credential, a required setting missing, the
     * thing it works on deleted. Anything unclassified is retried: a
     * transient failure called final loses the run, while a permanent one
     * called transient costs only the policy's attempts.
     *
     * @param string $msg
     * @param bool   $final
     * @return null
     */
    protected function fail( $msg, $final = false ) {

        $this->markFailed( $msg, self::EXIT_FAILED, $final );

        \OWA\Core\CoreAPI::notice( $msg );

        return null;
    }

    /**
     * Given an argument or value it cannot act on. A failure, exiting 2.
     *
     * Final: a job's arguments are the same on every attempt.
     *
     * @param string $msg
     * @return null
     */
    protected function usageError( $msg ) {

        $this->markFailed( $msg, self::EXIT_USAGE, true );

        \OWA\Core\CoreAPI::notice( $msg );

        return null;
    }

    /**
     * Record a failure without logging it, for a failure already reported.
     *
     * The first failure decides, as it decides the message and the exit code:
     * a failure that may be retried is not made final by a later one.
     *
     * @param string $msg
     * @param int    $code
     * @param bool   $final  see fail()
     * @return void
     */
    protected function markFailed( $msg, $code, $final = false ) {

        if ( $this->cli_outcome !== 'failed' ) {

            $this->cli_final = (bool) $final;
        }

        $this->cli_outcome = 'failed';
        $this->cli_message = $this->cli_message ?: $msg;

        // The first failure decides the exit code.
        if ( $this->exit_on_failure && ! self::$exit_code ) {

            self::$exit_code = (int) $code;
        }
    }

    /**
     * Run the command, and count as failures the answers Controller::doAction()
     * gives without reaching action(): a refused capability, failed validation,
     * and schema updates pending.
     *
     * @return mixed
     */
    public function doAction() {

        $data = parent::doAction();

        if ( $this->cli_outcome !== 'ok' ) {

            return $data;
        }

        $view = is_array( $data ) && isset( $data['view'] ) ? $data['view'] : null;

        if ( $view === 'base.error' ) {

            $this->markFailed( 'capability check failed', self::EXIT_FAILED );

        } elseif ( ! empty( $this->v ) && $this->v->hasErrors === true ) {

            // Final, as usageError(): the arguments are the same on every attempt.
            $this->markFailed( 'invalid arguments', self::EXIT_USAGE, true );

        } elseif ( $view === 'base.genericCli' ) {

            $this->markFailed( 'schema updates are pending', self::EXIT_FAILED );
        }

        return $data;
    }

    /**
     * The argument names a command reads: those its usage() declares, by
     * name, and GLOBAL_ARGUMENTS.
     *
     * Null for a command that does not declare its own usage(), whose
     * arguments are therefore unknown.
     *
     * @param string $class
     * @return string[]|null
     */
    public static function acceptedArguments( $class ) {

        if ( ! is_string( $class ) || ! class_exists( $class ) || ! method_exists( $class, 'usage' ) ) {

            return null;
        }

        $method = new \ReflectionMethod( $class, 'usage' );

        if ( $method->getDeclaringClass()->getName() !== ltrim( $class, '\\' ) ) {

            return null;
        }

        $usage = $class::usage();
        $names = self::GLOBAL_ARGUMENTS;

        foreach ( array_keys( (array) ( $usage['arguments'] ?? [] ) ) as $arg ) {

            $names[] = \OWA\Core\Params::name( (string) $arg );
        }

        return $names;
    }

    /**
     * acceptedArguments() as $class answers it, which a command overrides to
     * read a retired argument only to refuse it.
     *
     * @param string $class
     * @return string[]|null
     */
    public static function acceptedArgumentsFor( $class ) {

        return is_string( $class ) && method_exists( $class, 'acceptedArguments' )
            ? $class::acceptedArguments( $class )
            : self::acceptedArguments( $class );
    }

    /**
     * The arguments in $params that $class does not take; empty when it takes
     * them all or does not say what it takes.
     *
     * @param string $class
     * @param array<int|string, mixed> $params argument => value, any type: only the keys are read
     * @return list<string>
     */
    public static function undeclaredArguments( $class, array $params ) {

        $accepted = self::acceptedArgumentsFor( $class );

        if ( $accepted === null ) {

            return [];
        }

        return array_values( array_diff( array_map( 'strval', array_keys( $params ) ), $accepted ) );
    }

    /**
     * The outcome, for the dispatcher. The FIRST message wins, so what is
     * recorded is the first thing that went wrong rather than the last.
     * final is true when the run failed and gave up (fail()).
     *
     * @return array{outcome: string, message: string, final: bool}
     */
    public function getCliOutcome() {

        return [
            'outcome' => $this->cli_outcome,
            'message' => $this->cli_message,
            'final'   => $this->cli_outcome === 'failed' && $this->cli_final,
        ];
    }

    /**
     * The command's own retry policy, for a job that attaches none: any of
     * Classes\RetryPolicy::KEYS, merged over the default for the job's kind.
     * Empty: the default.
     *
     * @return array<string, mixed>
     */
    public static function retryPolicy() {

        return [];
    }

    /**
     * How long this command's lock should be trusted without proof of life.
     *
     * A CRASH-RECOVERY TIMEOUT, NOT A RUNTIME BUDGET: on any normal path the
     * lock is released in a finally and this is never consulted. It decides only
     * how long after a process dies before another run may assume it is really
     * dead. Too short duplicates a long job; too long delays recovery. Those
     * costs are asymmetric, so err long.
     *
     * Override to DERIVE the number from work the command can see up front --
     * the estimate is read-only and happens before the lock is taken.
     * Configuration deliberately cannot override it: an operator has strictly
     * less information than code that has just planned the work, and the real
     * need behind "I want a shorter lease" is answered by
     * `schedule-run --force-release`.
     *
     * A command with a fixed lease declares it as `const JOB_LEASE`, which
     * the one-off queue reads too (JobQueue::leaseFor()), so it is stated
     * once.
     *
     * @return int seconds
     */
    public function getJobLease() {

        return defined( static::class . '::JOB_LEASE' ) ? (int) constant( static::class . '::JOB_LEASE' ) : self::DEFAULT_JOB_LEASE;
    }

    /**
     * Give the lock proof of life, extending it.
     *
     * THE COMMAND CALLS THIS, NOT THE DISPATCHER. The dispatcher runs jobs
     * in-process and synchronously, so while a job works it is blocked inside
     * that call with no thread to refresh from; pcntl_alarm does not rescue it
     * either, because signal handlers run between opcodes and a job blocked in
     * mysqli never yields to one. Only the command knows where it has a safe
     * point.
     *
     * A NO-OP when the command is run by hand, so a scheduled command and a
     * hand-run one behave identically.
     *
     * Loop-shaped jobs should call this every N iterations. A command that is
     * one long blocking statement -- partition-rotate is -- cannot, which is
     * exactly why it wants a long lease instead.
     *
     * A lease that is no longer this run's -- it ran out, and another run took
     * the job over -- stops the run: JobLeaseLost is thrown, and the scheduler
     * records no outcome for it.
     *
     * @return void
     * @throws JobLeaseLost
     */
    protected function heartbeat() {

        if ( $this->job_lease && ! $this->job_lease->refresh( $this->getJobLease() ) ) {

            throw new JobLeaseLost( sprintf( '%s lost its job lease: another run has taken the job over.', static::class ) );
        }
    }

    /**
     * Hand this run its lock, so heartbeat() has something to refresh.
     *
     * @param \OWA\Module\Base\Classes\JobLease $lease
     * @return void
     */
    public function setJobLease( $lease ) {

        $this->job_lease = $lease;
    }

    /**
     * A byte count as KB, or as MB above one megabyte.
     *
     * @param  int|float $bytes
     * @return string
     */
    protected static function readableSize( $bytes ) {

        return $bytes > 1048576
            ? sprintf( '%.1f MB', $bytes / 1048576 )
            : sprintf( '%.0f KB', $bytes / 1024 );
    }

    /**
     * Put report lines on standard output.
     *
     * Commands that CHANGE something report through CoreAPI::notice(), and
     * should: what they did is a record worth keeping, and the console handler
     * puts it on stdout as well as in the log. A report is not that. Routed
     * through the logger it would be stamped with a timestamp, a pid and a level
     * on every line, interleaved with debug output on an installation running
     * the development handler, and written into the error log on each run --
     * which, for a command sitting beside a job that runs from cron, would make
     * the report the noisiest thing in it.
     *
     * Refusals still go through notice(), because those are events.
     *
     * @param string|string[] $lines
     * @return void
     */
    protected function write( $lines ) {

        // The constructor exits unless this is a CLI request, so STDOUT is the
        // CLI SAPI's own constant. The fallback is for a test harness that has
        // not defined it.
        $out = defined( 'STDOUT' ) ? STDOUT : fopen( 'php://output', 'w' );

        foreach ( (array) $lines as $line ) {

            fwrite( $out, $line . PHP_EOL );
        }
    }

    /**
     * A command's invalid arguments, reported through notice(). doAction()
     * sets the exit code (2).
     *
     * @param mixed $errors the controller's validation_errors
     * @return void
     */
    public static function reportInvalidArguments( $errors ) {

        \OWA\Core\CoreAPI::notice( "Command failed. There were some errors:\n"
            . json_encode( [ $errors ], JSON_PRETTY_PRINT ) );
    }
}
