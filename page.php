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

require_once 'wa_settings_class.php';
require_once 'owa_controller.php';

define ('WA_BASE_URL', $_SERVER['SERVER_NAME']);
define ('WA_GRAPH_URL', WA_BASE_URL);

define('WA_DB_NAME', DB_NAME);     // The name of the database
define('WA_DB_USER', DB_USER);     // Your MySQL username
define('WA_DB_PASSWORD', DB_PASSWORD); // ...and password
define('WA_DB_HOST', DB_HOST);     //

// Set URI
if (isset($_GET['uri']) && !empty($_GET['uri'])):
	$app_params['uri'] = base64_decode($_GET['uri']);
else: 
	$app_params['uri'] = $_SERVER['HTTP_REFERER'];
endif;

if (!isset($app_params['uri'])):
	exit;
endif;

// Set Referer
$app_params['referer'] = base64_decode($_GET['referer']);
	
// Set page Type
$app_params['page_type'] = $_GET['page_type'];

//Set page ID
$app_params['page_id'] = $_GET['page_id'];
	
// Track users by the email address
$app_params['user_email'] = $_GET['user_email']; 
	
// Track users who have a named account
$app_params['user_name'] = $_GET['user_name'];

// Set Page Title
$app_params['page_title'] = urldecode($_GET['page_title']);

// Track unique feeds	
//$app_params['feed_subscription_id'] = ;
	
// Provide an ID for this instance
$app_params['site_id'] = $_GET['site_id'];
	
// Process the request
owa::process_request($app_params);
	
/////
header('Content-type: image/gif');
header('P3P: CP="NOI NID ADMa OUR IND UNI COM NAV"');
header('Expires: Sat, 22 Apr 1978 02:19:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

printf(
  '%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%',
  71,73,70,56,57,97,1,0,1,0,128,255,0,192,192,192,0,0,0,33,249,4,1,0,0,0,0,44,0,0,0,0,1,0,1,0,0,2,2,68,1,0,59
);
?>
