<?

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

require_once('../owa_settings_class.php');

/**
 * OWA Configuration File
 * 
 * This configuration file is used to store the various settings that OWA will use
 * when invoked by the calling application. 
 * 
 * Strings should be enclosed in 'quotes'.
 * Integers should be given literally (without quotes).
 * Boolean values may be true or false (never quotes).
 */

$OWA_CONFIG =  &owa_settings::get_settings();


// Logs errors to an error log file
// Possible values are true and false
$OWA_CONFIG['log_errors'] = true;


// Namespace prefix for cookies and database tables
// Default is 'owa_'
$OWA_CONFIG['log_errors'] = 'owa_';

// Feed subscription id
// Default is 'sid'
$OWA_CONFIG['feed_subscription_id'] = 'sid';

// Unique ID that you can use to track multiple sites with the same instance of OWA
// Default value is 1
$OWA_CONFIG['site_id'] = 1;

// Session Length (in seconds)
// Default value: 1800
$OWA_CONFIG['session_length'] = 1800;

// Print debug messages in the browser
// Options: true or false
$OWA_CONFIG['debug_to_screen'] = false;

// Optional info table name
$OWA_CONFIG['optinfo_table'] = 'optinfo';

// Debug Level
// Possible values: 0 = no debug, 1 - sql statements
$OWA_CONFIG['debug_level'] = 1;

// Database type
// Possible values: wordpress, mysql
$OWA_CONFIG['db_type'] = 'wordpress';

// Name of the database
$OWA_CONFIG['db_name'] = '';

// Database user name
$OWA_CONFIG['db_user'] = '';

// Password for database user
$OWA_CONFIG['db_password'] = '';

// Database host
// Could be localhost but is usually
$OWA_CONFIG['db_host'] = '';

// Resolve host names
// You might want to turn this off if you have a lot of traffic 
// and are NOT running OWA in async mode.
$OWA_CONFIG['resolve_hosts'] = true;

// Log requests for feeds
$OWA_CONFIG['log_feedreaders'] = true;

// Log requests from known robots
$OWA_CONFIG['log_robots'] = false;

// Create and log sessions
$OWA_CONFIG['log_sessions'] = true;

// Run in asynchronous mode.
$OWA_CONFIG['async_db'] = false;

// Directory where event log is stored wen running in async mode
// This directory must be read and writable by php or apache
// Default is owa/logs/
$OWA_CONFIG['async_log_dir'] = '';

			'async_log_file'				=> 'events.txt',
			'async_error_log_file'			=> 'events_error.txt',
			'error_email'					=> true,
			'notice_email'					=> 'peter@oncefuture.com',
			'error_log_file'				=> OWA_BASE_DIR . '/logs/errors.txt',
			'search_engines.ini'			=> OWA_BASE_DIR . '/conf/search_engines.ini',
			'query_strings.ini'				=> OWA_BASE_DIR . '/conf/query_strings.ini',
			'os.ini'						=> OWA_BASE_DIR . '/conf/os.ini',
			'db_class_dir'					=> OWA_BASE_DIR . '/db/',
			'templates_dir'					=> OWA_BASE_DIR . '/reports/templates/',
			'plugin_dir'					=> OWA_BASE_DIR . '/plugins/',
			'geolocation_lookup'            => true,
			'geolocation_service'			=> 'hostip',
			'report_wrapper'				=> 'wordpress.tpl'





?>