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

$url = parse_url(getenv("CLEARDB_DATABASE_URL"));

define('OWA_DB_TYPE', 'mysql'); // options: mysql
define('OWA_DB_NAME', substr($url["path"], 1)); // name of the database
define('OWA_DB_HOST', $url["host"]); // host name of the server housing the database
define('OWA_DB_USER', $url["user"]); // database user
define('OWA_DB_PORT', '3306'); // port of database
define('OWA_DB_PASSWORD', $url["pass"]); // database user's password
define('OWA_DEBUG',true);

/**
 * AUTHENTICATION KEYS AND SALTS
 *
 * Change these to different unique phrases.
 */
define('OWA_NONCE_KEY', '4EbaVxJo9vBYQOMdMeA3QU6Ze1A8luQT');  
define('OWA_NONCE_SALT', 'SiSehdkZbuoAGoAb6Y2ju2Q4f0Byyy8y');
define('OWA_AUTH_KEY', 'vIt14MUzsxDW0jPZXV4xlf3sxCHFRH2L');
define('OWA_AUTH_SALT', 'RtVJ4FDL0S9tl3FemKMjzRMeMTTh5ly5');

/** 
 * PUBLIC URL
 *
 * Define the URL of OWA's base directory e.g. http://www.domain.com/path/to/owa/ 
 * Don't forget the slash at the end.
 */
 
// define('OWA_PUBLIC_URL', 'https://owa.tliveinc.com/');  
// $environment = getenv("environment");
// if(getenv("environment") == "review") {
//  $stage_url = getenv("HEROKU_APP_DEFAULT_DOMAIN_NAME") . '/';
//  define('OWA_PUBLIC_URL', $stage_url); 
// } else {
//  define('OWA_PUBLIC_URL', 'https://owa.tliveinc.com/');  
// }

$heroku_url = getenv("HEROKU_APP_DEFAULT_DOMAIN_NAME") . '/';
define('OWA_PUBLIC_URL', $heroku_url); 

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


?>
