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
 * SERVER ROLE
 *
 * What this server does. One server doing everything is the default, and
 * needs nothing here. To split an install across servers, give each one a
 * role; they share the database and, between collector and worker, a queue.
 *
 *   full       tracking (log.php), the admin and report screens (index.php),
 *              the REST API (api/), the CLI, the installer; runs the scheduler.
 *   collector  log.php only, with no database. Every beacon is queued for a
 *              worker. Needs a queue other than the file queue:
 *
 *                define('OWA_SERVER_ROLE', 'collector');
 *                define('OWA_SQS_QUEUE_URL', 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-tracker-ingest');
 *
 *              and no database settings or public URL. Credentials as in AWS
 *              SQS below. Run from its cron, to forward beacons the queue did
 *              not take:
 *
 *                * * * * * cd /path/to/owa && php cli.php cmd=drain-tracker-ingest forward_only=1
 *
 *   worker     the CLI and the scheduler: drains the queue, builds the cubes,
 *              rotates partitions. The database settings, keys and public URL
 *              below; with collectors, the same OWA_SQS_QUEUE_URL. Its crontab
 *              runs cmd=schedule-run (see SCHEDULED JOBS).
 *   console    the admin and report screens. The database settings, keys and
 *              public URL.
 *   api        the REST API. The database settings, keys and public URL.
 *
 * A comma list combines roles: 'console,api'. A request the role does not serve
 * is answered with a 404 (a CLI command with a refusal), and a missing setting
 * the role needs stops every request with a line in the error log.
 * collector, console and api run only the CLI commands marked for them;
 * cmd=server-check and cmd=instance-info run everywhere.
 *
 * Check a server with:
 *
 *   php cli.php cmd=server-check
 */

//define('OWA_SERVER_ROLE', 'full');

/**
 * DATABASE CONFIGURATION
 *
 * Connection info for databases that will be used by OWA. Every role but
 * collector needs it. OWA_DB_TYPE defaults to mysql.
 *
 */

define('OWA_DB_TYPE', 'yourdbtypegoeshere'); // options: mysql
define('OWA_DB_NAME', 'yourdbnamegoeshere'); // name of the database
define('OWA_DB_HOST', 'yourdbhostgoeshere'); // host name of the server housing the database
define('OWA_DB_USER', 'yourdbusergoeshere'); // database user
define('OWA_DB_PORT', '3306'); // port of database
define('OWA_DB_PASSWORD', 'yourdbpasswordgoeshere'); // database user's password

/**
 * OPTIONAL DATABASE SETTINGS
 *
 * OWA_DB_SQL_MODE -- the session sql_mode.
 *
 * Defaults to STRICT_ALL_TABLES, under which the database REFUSES a value that
 * does not fit rather than silently trimming it. A silent trim is data you
 * never learn you lost, which is why refusing is the default.
 *
 * Set it empty to restore the older permissive behaviour:
 *
 *      define('OWA_DB_SQL_MODE', '');
 *
 * Character encoding is NOT set here. The connection negotiates the widest
 * encoding automatically, and each table's encoding belongs to the entity that
 * defines it -- see Entity::setCharacterEncoding().
 */
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
 * Don't forget the slash at the end. Every role but collector needs it: links,
 * mail, the published tracking bundles and signed API requests are built from it.
 */
 
define('OWA_PUBLIC_URL', 'http://domain/path/to/owa/');

/**
 * TRACKING AND ASSETS URLS
 *
 *   OWA_PUBLIC_URL    the screens, the REST API, links and mail. Required.
 *   OWA_TRACKING_URL  where browsers reach log.php, such as a collector:
 *                     https://t.example.com/. Default: OWA_PUBLIC_URL.
 *   OWA_ASSETS_URL    where the built files under public/ are served from,
 *                     such as a CDN: the report screens' scripts, styles, fonts
 *                     and images, the tracker and its chunks, and the Profiles'
 *                     tracking bundles (public/tracker/<id>.js).
 *                     Default: OWA_PUBLIC_URL followed by public/.
 *
 * Tracking tags and bundles send beacons to OWA_TRACKING_URL and load the
 * tracker from OWA_ASSETS_URL; changing either republishes the bundles
 * (cmd=publish-trackers, or the scheduler). Set them on the servers that build
 * pages and bundles (full, console, worker); a collector needs neither.
 *
 * A CDN, best first:
 *
 *   1. In front of OWA's own hostname. Same origin, nothing else to set: leave
 *      OWA_ASSETS_URL unset.
 *   2. On a hostname of its own, set as OWA_ASSETS_URL. That is another origin,
 *      a CNAME included: the CDN must send Access-Control-Allow-Origin for the
 *      web fonts (and anything a script fetches). Plain <script> and
 *      <link rel="stylesheet"> loads, the tracker's lazy chunks among them, work
 *      across origins without it.
 *
 * Either way: public/tracker/ bundles keep fixed URLs (tags embed
 * tracker/<id>.js), so give that path a short cache time or purge it when the
 * bundles are published. Everything else is versioned (?version=) and can be
 * cached long. The server holding public/, where publish-trackers writes, must
 * be the CDN's origin, or share public/tracker/ with it.
 */

//define('OWA_TRACKING_URL', 'https://t.example.com/');
//define('OWA_ASSETS_URL', 'https://cdn.example.com/owa/');

/**
 * DATA DIRECTORIES
 *
 *   OWA_DATA_DIR   where OWA writes: its error log, the file event queue,
 *                  downloaded user-agent patterns and GeoIP data, and the
 *                  caches. Default: owa-data/ in this directory.
 *   OWA_CACHE_DIR  the file cache. Default: caches/ in the data directory.
 *
 * The web server user and the user that runs cli.php both write there, so both
 * need write access to it and to its logs/ and caches/.
 */

//define('OWA_DATA_DIR', '/var/lib/owa/');
//define('OWA_CACHE_DIR', '/var/cache/owa/');

/**
 * DEBUG
 *
 * Writes debug lines to OWA's error log as well as notices and errors, routes
 * PHP's own errors there, and has the tracker log to the browser console.
 * For development and for diagnosing a problem: the log grows quickly.
 */

//define('OWA_DEBUG', true);
 
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
 * QUEUEING TRACKING EVENTS
 *
 * Queue every beacon instead of writing it while log.php answers; the
 * drain-tracker-ingest job ingests them every minute, on the servers whose role
 * runs the scheduler. Off by default; a collector always queues. OWA_QUEUE_EVENTS,
 * the 1.x name, is still read while this is not set.
 *
 * The queue is the file queue under owa-data/logs/ unless a queue module's
 * constant is set: OWA_SQS_QUEUE_URL makes it AWS SQS. Set one at most.
 */

//define('OWA_QUEUE_TRACKING_EVENTS', true);

/**
 * AWS SQS TRACKING QUEUE (the sqs module)
 *
 * OWA_SQS_QUEUE_URL switches the sqs module on, on any server, and makes the
 * queue the tracking queue; its dead-letter queue is the same URL with -dlq.
 * Without OWA_QUEUE_TRACKING_EVENTS a full server still writes beacons as they
 * arrive and queues only one whose write fails. The region is read from the
 * URL; OWA_SQS_REGION overrides it.
 *
 * Credentials come from the AWS SDK's default chain -- environment, ~/.aws,
 * then the instance or task role -- unless set here; OWA_SQS_TOKEN is the
 * session token of temporary credentials. OWA never stores them.
 */

//define('OWA_SQS_QUEUE_URL', 'https://sqs.us-east-1.amazonaws.com/123456789012/owa-tracker-ingest');
//define('OWA_SQS_REGION', 'us-east-1');
//define('OWA_SQS_KEY', '');
//define('OWA_SQS_SECRET', '');
//define('OWA_SQS_TOKEN', '');

/**
 * SCHEDULED JOBS
 *
 * OWA runs its periodic maintenance from a SINGLE cron entry, on each server
 * whose role is full or worker:
 *
 *   * * * * * cd /path/to/owa && php cli.php cmd=schedule-run
 *
 * Every minute, because that line is not the schedule -- it is the finest
 * resolution the schedules below can use. Each job decides for itself how often
 * it actually runs. A boot costs about 66ms, so a day of ticks is roughly 95
 * seconds of CPU.
 *
 * These jobs ship (schedule-status lists them, with their schedules):
 *
 *   rebuild-cube                  every 5 minutes  builds each Property's reporting cube
 *   drain-tracker-ingest          every minute     ingests queued tracking events
 *   apply-custom-dimensions       every 15 minutes applies newly registered dimensions
 *   rotate-partitions             daily            extends the partition lead and merges
 *                                                  old periods; deletes what is older than
 *                                                  raw_retention_months and each Property's
 *                                                  cube_retention_months; nothing when unset
 *   replay-tracker-ingest         daily            retries dead-lettered tracking events;
 *                                                  deletes those older than raw_retention_months
 *   prune-tracker-ingest-archive  daily            deletes archived intake batches older than
 *                                                  raw_retention_months; nothing when unset
 *   prune-job-queue               daily            removes finished queued jobs
 *   fetch-notifications           daily            fetches OWA's update notices
 *
 * The daily and five-minute ones run at a time derived from this
 * installation, so installs sharing a database do not all start at once.
 *
 * Use OWA_SCHEDULED_JOBS to retune what ships, to add jobs the release does not
 * register, or to turn one off. It is keyed by JOB NAME; 'command' is the CLI
 * command that actually runs. They are separate so one command can be scheduled
 * more than once with different arguments.
 *
 * Overrides are per key: giving only 'params' keeps the shipped schedule, and
 * giving only 'schedule' keeps the shipped arguments.
 *
 * A 'description' says what a job does; the System Health screen shows it. A
 * job you add should have one.
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
//    // Keep six months of partitions ahead rather than twelve. How much history
//    // is kept is not a job argument: it is the raw_retention_months and
//    // cube_retention_months settings, and nothing is deleted while they are unset.
//    'rotate-partitions' => array( 'params' => array( 'months-ahead' => 6 ) ),
//
//    // Drain the tracking intake every five minutes rather than every minute.
//    'drain-tracker-ingest' => array( 'schedule' => '*/5 * * * *' ),
//
//    // The same command a second time, under its own name, with its own
//    // arguments and cadence.
//    'rotate-raw-weekly' => array(
//        'command'  => 'partition-rotate',
//        'params'   => array( 'table' => 'owa_event_raw' ),
//        'schedule' => '@weekly',
//    ),
//
//    // Turn a shipped job off.
//    // 'rotate-partitions' => array( 'schedule' => 'off' ),
//));


/**
 * REPORTING TIMEZONE
 *
 * The timezone statistics are bucketed into calendar days with. Defaults to
 * America/Los_Angeles if you set nothing, which is unlikely to be what you want.
 *
 *      define('OWA_TIMEZONE', 'Europe/London');
 *
 * SET THIS BEFORE YOU COLLECT DATA. The choice is not retroactive: each request
 * is filed under a calendar day at the moment it is recorded, using the timezone
 * in force then, and that day is written into the row. Changing it later applies
 * only to new traffic -- existing data keeps the boundaries it was recorded
 * with, and reports spanning the change will mix the two. Depending on how far
 * apart the zones are, a day boundary can move by up to 21 hours.
 *
 * IF YOU SET THIS, IT WINS. The admin UI can also set the timezone (Options ->
 * General), but a constant defined here overrides whatever is stored in the
 * database, for as long as it stays defined. Comment it out again and the stored
 * value takes over.
 *
 * That is true of every constant in this file: define it and it is authoritative;
 * leave it undefined and OWA uses the database value, then the built-in default.
 * The options form will tell you when a setting you are changing is being
 * overridden from here.
 *
 * Use any identifier from https://www.php.net/manual/en/timezones.php. An
 * unrecognised value is ignored rather than applied, because a timezone that
 * silently failed to take effect would file every subsequent request under the
 * wrong day. Note the UI's picker is organised by country and so does not offer
 * UTC -- this constant does.
 */
//define('OWA_TIMEZONE', 'Europe/London');


/**
 * LANGUAGE
 *
 * The locale of the user interface, read from conf/strings/<locale>.php (e.g. fr, pt_BR).
 */
//define('OWA_LOCALE', 'fr');


/**
 * MAXMIND GEOIP LICENCE KEY
 *
 * Only relevant if the Maxmind GeoIP module is activated.
 *
 * The GeoLite2 City database OWA looks IP addresses up in is free, but MaxMind
 * closed anonymous downloads at the end of 2019, so fetching it is
 * authenticated. Create a key at maxmind.com -- the account and the key cost
 * nothing -- and set it here:
 *
 *      $this->set('maxmind_geoip', 'db_license_key', 'your-key-here');
 *
 * That works because this file is included from inside a settings object, so
 * $this is that object. It is not a define() like the settings above.
 *
 * Then refresh the database with:
 *
 *      php cli.php cmd=update-geoip-db
 *
 * The database is worth refreshing periodically. IP ranges move between
 * countries and cities continuously, and a stale file does not fail -- it
 * answers, wrongly, and the reports look normal. MaxMind publish updates twice
 * a week.
 */

?>