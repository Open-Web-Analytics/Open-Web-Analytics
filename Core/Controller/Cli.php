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

    /**
     * Constructor
     *
     * @param array $params
     * @return \owa_controller
     */
    function __construct($params) {

        if ( \OWA\Core\CoreAPI::getSetting('base', 'request_mode') === 'cli' ) {

            return parent::__construct($params);

        } else {

            \OWA\Core\CoreAPI::notice("Controller not called from CLI");
            exit;
        }
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

        $this->cli_outcome = 'refused';
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

        $this->cli_outcome = 'failed';
        $this->cli_message = $this->cli_message ?: $msg;

        \OWA\Core\CoreAPI::notice( $msg );

        return null;
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

        return 21600;   // 6 hours
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