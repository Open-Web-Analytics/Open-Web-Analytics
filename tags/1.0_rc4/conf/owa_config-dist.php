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

// The web accessible URI of OWA's public htdocs folder
// This folder can be named whatever you'd like and placed anywhere 
// in the document root of your web server.
// $OWA_CONFIG['public_url'] = '/path/to/owa/public';

// Database type
// Possible values: mysql
// $OWA_CONFIG['db_type'] = 'mysql';

// Database Connection Class
// Possible values: wordpress, mysql
// $OWA_CONFIG['db_class'] = '';

// Name of the database
// $OWA_CONFIG['db_name'] = '';

// Database user name
// $OWA_CONFIG['db_user'] = '';

// Password for database user
// $OWA_CONFIG['db_password'] = '';

// Database host
// Could be localhost but is usually an actual host name (e.g. db.host.com)
// $OWA_CONFIG['db_host'] = '';

// Fetch settings from DB instead of this configuration file. 
// IF SET TO TRUE THEN ALL CHANGES BELOW THIS LINE WILL BE IGNORED
// $OWA_CONFIG['fetch_config_from_db'] = false;

////////////////////////////////////////////

// Namespace prefix for cookies and database tables
// Default is 'owa_'
// $OWA_CONFIG['ns'] = 'owa_';

// Feed subscription id
// Default is 'sid'
// $OWA_CONFIG['feed_subscription_id'] = 'sid';

// Unique ID that you can use to track multiple sites with the same instance of OWA
// Default value is 1
// $OWA_CONFIG['site_id'] = 1;

// Session Length (in seconds)
// Default value: 1800
// $OWA_CONFIG['session_length'] = 1800;

// Resolve host names
// You might want to turn this off if you have a lot of traffic 
// and are NOT running OWA in async mode.
// $OWA_CONFIG['resolve_hosts'] = true;

// Log requests for feeds
// $OWA_CONFIG['log_feedreaders'] = true;

// Log requests from known robots
// $OWA_CONFIG['log_robots'] = false;

// Create and log sessions
// $OWA_CONFIG['log_sessions'] = true;

// Run in asynchronous mode.
// $OWA_CONFIG['async_db'] = true;

// Error handler mode. Default is 'production'.
// Options: 'development'	- logs all debug and errors to seperate window or stdout 
//			'production' 	- only logs real errors to a file. 
// 			     			  Mails critical ones to notice email.
// $OWA_CONFIG['error_handler'] = 'production';

// Directory where event log is stored wen running in async mode
// This directory must be read and writable by php or apache
// This must be a full path to the directory. The default is /path/to/owa/logs/
// $OWA_CONFIG['async_log_dir'] = '';

// Name of Log file used to log events
// $OWA_CONFIG['async_log_file'] = 'events.txt';

// Name of Log file used to log events that the Async event processor had issues with
// $OWA_CONFIG['async_error_log_file'] = 'events_error.txt';

// Email address used to send various notices to
// $OWA_CONFIG['notice_email'] = '';

// Lookup Geo-location of visitors
// $OWA_CONFIG['geolocation_lookup']= true;

// Geo-location Service to use for lookups
// Possible options are: 'hostip'
// $OWA_CONFIG['geolocation_service']= 'hostip';

// Report Wrapper Template
// $OWA_CONFIG['report_wrapper']= 'standalone.tpl';

// Traffic Source Tracking Code Parameter
// $OWA_CONFIG['source_param'] = 'from';

// Feed Tracking Code Parameter
// This parameter is used in URLs to track feed subscribers
// $OWA_CONFIG['feed_subscription_param'] = 'sid';


?>