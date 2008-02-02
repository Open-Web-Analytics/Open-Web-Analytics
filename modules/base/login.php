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

require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_controller.php');
require_once(OWA_BASE_DIR.'/owa_auth.php');

/**
 * Login View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_loginView extends owa_view {
	
	function owa_loginView() {
		
		$this->owa_view();
		$this->priviledge_level = 'guest';
		
		return;
	}
	
	function construct($data) {
		
		$this->body->set_template('login_form.tpl');// This is the inner template
		$this->body->set('headline', 'Please login using the from below');
		$this->body->set('user_id', $data['user_id']);
		$this->body->set('go', $data['go']);
	
	}
}

class owa_loginController extends owa_controller {
	
	function owa_loginController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'guest';
	
		return;
	}
	
	function action() {
		
		$auth = &owa_auth::get_instance();
		$status = $auth->authenticateNewBrowser($this->params['user_id'], $this->params['password']);
		$data = array();
		
		// if authentication is successfull
		if ($status['auth_status'] == true):
			
			// redirect to url if present
			if (!empty($this->params['go'])):
				$url = urldecode($this->params['go']);
				
				$this->e->debug("redirecting browser to...:". $url);
				owa_lib::redirectBrowser($url);
			//else redirect to home page
			else:
				$data['view_method'] = 'redirect';
				$data['do'] = 'base.reportDashboard';
			endif;
		// return error view		
		else:
		
			$data['view_method'] = 'delegate';
			$data['view'] = 'base.login';
			$data['go'] = urldecode($this->params['go']);
			$data['go'] = urlencode($this->params['go']);
			$data['error_msg'] = $this->getMsg(2002);
			$data['user_id'] = $this->params['user_id'];
		
		endif;
		
		return $data;
	}
	
	
}

?>