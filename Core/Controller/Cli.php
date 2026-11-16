<?php
namespace OWA\Core\Controller;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//
// $Id$
//


/**
 * CLI Controller Class
 *
 * This controller should be used for internal management pages/actions that require authentication
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class Cli extends \OWA\Core\AdminController {

    var $is_admin = true;

    /** getJobLease() for a command that does not derive its own, in seconds. */
    const DEFAULT_JOB_LEASE = 21600;   // 6 hours

    /**
     * Constructor
     *
     * @param array $params
     * @return \OWA\Core\Controller
     */
    function __construct($params) {

        if ( \OWA\Core\RequestType::is( \OWA\Core\RequestType::CLI ) ) {

            return parent::__construct($params);

        } else {

            \OWA\Core\CoreAPI::notice("Controller not called from CLI");
            exit;
        }
    }

    /**
     * The server roles this command runs under (Core\ServerRole); cli.php
     * refuses it on any other. full runs every command. A command a
     * collector, console or api server may run names that role.
     *
     * Static, like usage(): cli.php asks before the controller is built.
     *
     * @return string[]
     */
    public static function serverRoles() {

        return array( \OWA\Core\ServerRole::FULL, \OWA\Core\ServerRole::WORKER );
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
     * Each argument is keyed as it is typed -- `property=<id>`, `--dry-run` --
     * and the name before any `=`, without the dashes, is what the controller
     * reads with getParam(). WikiDocsCliJobsTest holds the source to that name.
     *
     * @return array{description: string, arguments: array<string,string>}
     */
    public static function usage() {

        return array( 'description' => '', 'arguments' => array() );
    }

    /**
     * What this command actually did: 'ok', 'refused' or 'failed'.
     *
     * doAction() returns $this->data and CLI actions return void, so until the
     * scheduler existed a command that declined to act and one that did the work
     * looked identical to a caller. That is fine for a person reading a terminal
     * and useless to a dispatcher, which has to decide whether an occurrence was
     * satisfied and record why.
     *
     * Defaults to 'ok': a command that never says otherwise ran to completion,
     * which is what every existing command means today. Nothing had to be
     * rewritten; commands opt in one at a time by calling refuse() or fail()
     * where they already call notice().
     *
     * @var string
     */
    protected $cli_outcome = 'ok';

    /** @var string */
    protected $cli_message = '';

    /** The exit code of a command that failed. */
    const EXIT_FAILED = 1;

    /** The exit code of a command given an argument it does not take, or a value it cannot read. */
    const EXIT_USAGE = 2;

    /** Arguments every command accepts, beside those its usage() declares. */
    const GLOBAL_ARGUMENTS = array( 'cmd' );

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
     * Tried and failed. The occurrence is left unsatisfied and retried.
     *
     * This matters more than it looks: Db::query() swallows SQL errors and
     * returns falsy, so without commands calling this, a job whose ALTER TABLE
     * was rejected by the server would record 'ok' indefinitely.
     *
     * @param string $msg
     * @return null
     */
    protected function fail( $msg ) {

        $this->markFailed( $msg, self::EXIT_FAILED );

        \OWA\Core\CoreAPI::notice( $msg );

        return null;
    }

    /**
     * Given an argument or value it cannot act on. A failure, exiting 2.
     *
     * @param string $msg
     * @return null
     */
    protected function usageError( $msg ) {

        $this->markFailed( $msg, self::EXIT_USAGE );

        \OWA\Core\CoreAPI::notice( $msg );

        return null;
    }

    /**
     * Record a failure without logging it, for a failure already reported.
     *
     * @param string $msg
     * @param int    $code
     * @return void
     */
    protected function markFailed( $msg, $code ) {

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
    function doAction() {

        $data = parent::doAction();

        if ( $this->cli_outcome !== 'ok' ) {

            return $data;
        }

        $view = is_array( $data ) && isset( $data['view'] ) ? $data['view'] : null;

        if ( $view === 'base.error' ) {

            $this->markFailed( 'capability check failed', self::EXIT_FAILED );

        } elseif ( ! empty( $this->v ) && $this->v->hasErrors === true ) {

            $this->markFailed( 'invalid arguments', self::EXIT_USAGE );

        } elseif ( $view === 'base.genericCli' ) {

            $this->markFailed( 'schema updates are pending', self::EXIT_FAILED );
        }

        return $data;
    }

    /**
     * The argument names a command reads: those its usage() declares, by the
     * name before any `=` and without dashes, and GLOBAL_ARGUMENTS.
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

        foreach ( array_keys( (array) ( $usage['arguments'] ?? array() ) ) as $arg ) {

            $names[] = ltrim( explode( '=', (string) $arg, 2 )[0], '-' );
        }

        return $names;
    }

    /**
     * acceptedArguments() as $class answers it, which a command overrides to
     * read a retired argument only to refuse it. A command's controller need
     * not be a Cli (sitesAddCli extends its screen).
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
     * @param array  $params
     * @return string[]
     */
    public static function undeclaredArguments( $class, array $params ) {

        $accepted = self::acceptedArgumentsFor( $class );

        if ( $accepted === null ) {

            return array();
        }

        return array_values( array_diff( array_map( 'strval', array_keys( $params ) ), $accepted ) );
    }

    /**
     * The outcome, for the dispatcher. The FIRST message wins, so what is
     * recorded is the first thing that went wrong rather than the last.
     *
     * @return array
     */
    public function getCliOutcome() {

        return array(
            'outcome' => $this->cli_outcome,
            'message' => $this->cli_message,
        );
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
     * @return int seconds
     */
    public function getJobLease() {

        return self::DEFAULT_JOB_LEASE;
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
     * @return void
     */
    protected function heartbeat() {

        if ( $this->job_lease ) {

            $this->job_lease->refresh( $this->getJobLease() );
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
     * @param string[] $lines
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
}

?>