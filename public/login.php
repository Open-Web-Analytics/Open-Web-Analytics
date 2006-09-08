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

include_once('set_env.php');
require_once(OWA_BASE_DIR.'/owa_auth.php');
require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_template.php');
require_once(OWA_BASE_DIR.'/owa_php.php');
require_once(OWA_BASE_DIR.'/eventQueue.php');
/**
 * Login Form Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

// Instantiate OWA
$owa = new owa_php;

// Clean Input arrays
if ($_POST):
	$params = owa_lib::inputFilter($_POST);
else:
	$params = owa_lib::inputFilter($_GET);
endif;

// Decode the redirect URL
$params['go'] = urldecode($params['go']);


// page controllers

if (!empty($params['page'])):

	$page = & new owa_template($params);	
	$page->set_template($owa->config['report_wrapper']);
	$body = & new owa_template($params); 
			
	switch ($params['page']) {
		
		case "login":
			$params['user_id'] = $_COOKIE['u'];
			$body->set_template('login_form.tpl');// This is the inner template
			$body->set('headline', 'Please login using the from below');
			$body->set('u', $_COOKIE['u']);
			
			if (!empty($params['go'])):
			
				$body->set('go', $params['go']);
			else:
				$body->set('go', $page->config['home_url']);
			endif;
				
			$body->set('status_msg', '');
			
			break;	
		
		case "bad_pass":
			$params['user_id'] = $_COOKIE['u'];
			$body->set_template('login_form.tpl');// This is the inner template
			$body->set('headline', 'Login Failed');
			$body->set('u', $_COOKIE['u']);
			
			if (!empty($params['go'])):
			
				$body->set('go', $params['go']);
			else:
				$body->set('go', $page->config['home_url']);
			endif;
				
			$body->set('status_msg', 'Your Password or user name was not correct.');
			
			break;
			
		case "not_priviledged":
			print "you are not priviledege to access the requested resource.";
			break;
		case "request_new_password":
			$body->set_template('request_password_form.tpl');// This is the inner template
			$body->set('headline', 'Type in the email addressthat you registered with');
			$body->set('u', $_COOKIE['u']);
			break;
		case "request_new_password_success":
			$body->set_template('status.tpl');// This is the inner template
			$body->set('headline', 'Almost done!');
			$body->set('status_msg', 'An e-mail has been sent to your address with further instructions.');
			break;
		case "request_new_password_error":
			$body->set_template('error.tpl');// This is the inner template
			$body->set('page_h1', 'Houston, we have a problem...');
			$body->set('error_msg', 'The e-mail address that you entered was not found in our database.');
			break;
		case "reset_password":
			
			$auth = & owa_auth::get_instance();
			$status = $auth->authenticateUserTempPasskey($params['k']);
			
			$body->set_template('reset_password_form.tpl');// This is the inner template
			$body->set('headline', 'Choose a new password...');
			$body->set('key', $params['k']);
			$body->set('status_msg', '');
			break;
		case "reset_password_success":
			$body->set_template('status.tpl');// This is the inner template
			$body->set('page_h1', 'Success!');
			$body->set('status_msg', 'Your Password has been changed.');
			break;
			
	}
	
	$page->set('content', $body);
	echo $page->fetch();
	
endif;


// Action controllers
if (!empty($params['action'])):

	switch ($params['action']) {
		
		case "auth":
			$owa->e->debug('performing authentication');
			$auth = &owa_auth::get_instance();
			$status = $auth->authenticateNewBrowser($params['user_id'], $params['password']);
			
			if ($status == true):
					$url = $params['go'];
			else:
					$url = $_SERVER['PHP_SELF'].'?page=bad_pass&'.$params['go'];
			endif;
			break;
		case "request_new_password":
			$auth = &owa_auth::get_instance();
			$status = $auth->setTempPasskey($params['email_address']);
			
			if ($status == true):
					$url = $_SERVER['PHP_SELF'].'?page=request_new_password_success';
			else:
					$url = $_SERVER['PHP_SELF'].'?page=request_new_password_error';
			endif;
			break;
		case "reset_password":
			$auth = & owa_auth::get_instance();
			$status = $auth->authenticateUserTempPasskey($params['k']);
			
			//check to see if psswords match
			
			// log to event queue
			if ($status == true):
				$eq = & eventQueue::get_instance();
				$new_password = array('key' => $params['k'], 'password' => $auth->encryptPassword($params['password']), 'ip' => $_SERVER['REMOTE_ADDR']);
				$eq->log($new_password, 'user.reset_password');
				
				$url = $_SERVER['PHP_SELF'].'?page=reset_password_success';
				
			endif;
			
			break;
			
	}
	// 301 redirect to URL 
	header ('Location: '.$url);
	header ('HTTP/1.0 301 Moved Permanently');
	return;
	
endif;

?>
