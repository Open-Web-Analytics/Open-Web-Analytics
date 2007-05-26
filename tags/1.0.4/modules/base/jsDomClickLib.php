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

require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_controller.php');

/**
 * View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_jsDomClickLibView extends owa_view {
	
	function owa_jsDomClickLibView() {
		
		$this->owa_view();
		$this->priviledge_level = 'guest';
		
		return;
	}
	
	function construct($data) {
		
		// load body template
		$this->t->set_template('wrapper_blank.tpl');
		
		$this->body->set('log_clicks', true);
		
		$this->body->set('is_embedded', true);
		
		if (empty($data['site_id'])):
			$data['site_id'] = $this->config['site_id'];
		endif;
		
		$this->body->set('site_id', $data['site_id']);
		
		// load body template
		$this->body->set_template('js_logger.tpl');
		
		return;
	}
	
	
}

/**
 * Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_jsDomClickLibController extends owa_controller {
	
	function owa_jsDomClickLibController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'guest';
	}
	
	function action() {
		
		// Control logic
		
		// Setup the data array that will be returned to the view.
		
		$data['view_method'] = ''; // Delegate, redirect
		$data['view'] = '';
		$data['subview'] = '';
		$data['error_msg'] = '';
			
			
		return $data;
	}
	
	
}

?>