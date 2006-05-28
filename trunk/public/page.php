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

include_once('set_env.php');
require_once(OWA_BASE_DIR.'/owa_php.php');

/**
 * HTTP Invocation handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

// Turn off the delay first hit feature
$config['delay_first_hit'] = false;

// Setup new OWA caller object
$l = new owa_php($config);

// Return 1x1 pixel
header('Content-type: image/gif');
header('P3P: CP="'.$l->config['p3p_policy'].'"');
header('Expires: Sat, 22 Apr 1978 02:19:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');

// Set page URL
if (isset($_GET['page_uri']) && !empty($_GET['page_uri'])):
	$app_params['uri'] = base64_decode($_GET['page_uri']);
else: 
	$app_params['uri'] = $_SERVER['HTTP_REFERER'];
endif;

if (empty($app_params['uri'])):
	print 'no uri';
	exit;
endif;

// Set Referer
$app_params['referer'] = base64_decode($_GET['referer']);
	
// Set page Type
$app_params['page_type'] = urldecode($_GET['page_type']);

//Set page ID
$app_params['page_id'] = urldecode($_GET['page_id']);
	
// Track users by the email address
$app_params['user_email'] = urldecode($_GET['user_email']); 
	
// Track users who have a named account
$app_params['user_name'] = urldecode($_GET['user_name']);

// Set Page Title
$app_params['page_title'] = urldecode($_GET['page_title']);
	
// Provide an ID for this instance
$app_params['site_id'] = $_GET['site_id'];
	
// Track the request
$l->log($app_params);

printf(
  '%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%c%',
  71,73,70,56,57,97,1,0,1,0,128,255,0,192,192,192,0,0,0,33,249,4,1,0,0,0,0,44,0,0,0,0,1,0,1,0,0,2,2,68,1,0,59
);

?>
