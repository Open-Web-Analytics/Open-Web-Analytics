<?php

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
 * OWA Configuration
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
 
/**
 * DATABASE CONFIGURATION
 *
 * Connection info for databases that will be used by OWA.
 *
 */

define('OWA_DB_TYPE', 'yourdbtypegoeshere'); // options: mysql
define('OWA_DB_NAME', 'yourdbnamegoeshere'); // name of the database
define('OWA_DB_HOST', 'yourdbhostgoeshere'); // host name of the server housing the database
define('OWA_DB_USER', 'yourdbusergoeshere'); // database user
define('OWA_DB_PORT', '3306'); // port of database
define('OWA_DB_PASSWORD', 'yourdbpasswordgoeshere'); // database user's password

/**
 * AUTHENTICATION KEYS AND SALTS
 *
 * Change these to different unique phrases.
 */
define('OWA_NONCE_KEY', 'yournoncekeygoeshere');
define('OWA_NONCE_SALT', 'yournoncesaltgoeshere');
define('OWA_AUTH_KEY', 'yourauthkeygoeshere');
define('OWA_AUTH_SALT', 'yourauthsaltgoeshere');

/**
 * PUBLIC URL
 *
 * Define the URL of OWA's base directory e.g. http://www.domain.com/path/to/owa/
 * Don't forget the slash at the end.
 */
 
define('OWA_PUBLIC_URL', 'http://domain/path/to/owa/');

/**
 * OWA ERROR HANDLER
 *
 * Overide OWA error handler. This should be done through the admin GUI, but
 * can be handy during install or development.
 * 
 * Choices are:
 *
 * 'production' - will log only critical errors to a log file.
 * 'development' - logs al sorts of useful debug to log file.
 */

//define('OWA_ERROR_HANDLER', 'development');

/**
 * LOG PHP ERRORS
 *
 * Log all php errors to OWA's error log file. Only do this to debug.
 */

//define('OWA_LOG_PHP_ERRORS', true);
 
/**
 * OBJECT CACHING
 *
 * Override setting to cache objects. Caching will increase performance.
 */

//define('OWA_CACHE_OBJECTS', true);

/**
 * CONFIGURATION ID
 *
 * Override to load an alternative user configuration
 */

//define('OWA_CONFIGURATION_ID', '1');

/**
 * STATIC CONFIG ONLY
 *
 * When true, OWA does NOT read your saved settings from the owa_configuration
 * database table at boot. It runs on the built-in defaults plus whatever this
 * config file defines. This suppresses the two DB queries every OWA process
 * would otherwise issue on boot (the connection handshake and the config read),
 * so a dedicated logging node that only queues incoming tracking events to a
 * file can accept beacons with zero database access.
 *
 * ONLY enable this on a node whose job is to queue events (see the "Event
 * Queueing" wiki page). Because saved settings are not loaded, any non-default
 * setting such node relies on (tracked sites, the queue type, async_log_dir,
 * etc.) must be pinned here in owa-config.php. Do NOT enable it on the instance
 * that serves reports or the admin UI -- those need the database-backed config.
 */

//define('OWA_USE_STATIC_CONFIG_ONLY', true);


/**
 * SCHEDULED JOBS
 *
 * OWA runs its periodic maintenance from a SINGLE cron entry:
 *
 *   * * * * * php /path/to/owa/cli.php cmd=schedule-run
 *
 * Every minute, because that line is not the schedule -- it is the finest
 * resolution the schedules below can use. Each job decides for itself how often
 * it actually runs. A boot costs about 66ms, so a day of ticks is roughly 95
 * seconds of CPU.
 *
 * Out of the box exactly one job is registered: partition-rotate, monthly, with
 * NO arguments -- it extends the fact tables' partition lead and merges old
 * periods to stay within the open-file budget, and never deletes anything.
 *
 * Use OWA_SCHEDULED_JOBS to retune what ships, to add jobs the release does not
 * register, or to turn one off. It is keyed by JOB NAME; 'command' is the CLI
 * command that actually runs. They are separate so one command can be scheduled
 * more than once with different arguments.
 *
 * Overrides are per key: giving only 'params' keeps the shipped schedule, and
 * giving only 'schedule' keeps the shipped arguments.
 *
 * Schedules are standard five-field cron expressions, or the usual @hourly,
 * @daily, @weekly, @monthly and @yearly shorthands, or 'off'. They are read in
 * this installation's configured timezone. An entry that cannot be read disables
 * THAT job and nothing else -- it is never given a default, because running
 * something on a cadence nobody chose is worse than not running it.
 *
 * Check what is registered, and why anything is not running, with:
 *
 *   php cli.php cmd=schedule-status
 */

//define('OWA_SCHEDULED_JOBS', array(
//
//    // Keep two years of history. NOTHING is deleted unless you ask for it
//    // here: the shipped job runs with no keep= at all.
//    'partition-rotate' => array( 'params' => array( 'keep' => 24 ) ),
//
//    // Drain the event queue every two minutes. Not registered by default,
//    // because whether to drain at all depends on whether you queue events.
//    'drain-queue' => array(
//        'command'  => 'processEventQueue',
//        'schedule' => '*/2 * * * *',
//    ),
//
//    // The same command a second time, under its own name, with its own
//    // arguments and cadence.
//    'rotate-request-table' => array(
//        'command'  => 'partition-rotate',
//        'params'   => array( 'table' => 'owa_request' ),
//        'schedule' => '@weekly',
//    ),
//
//    // Turn a shipped job off.
//    // 'partition-rotate' => array( 'schedule' => 'off' ),
//));

/**
 * DISABLE THE SCHEDULER
 *
 * Stops every job without touching crontab -- for a migration or an incident.
 */

//define('OWA_SCHEDULER_ENABLED', false);

?>