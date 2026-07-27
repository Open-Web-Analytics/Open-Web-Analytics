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

?>