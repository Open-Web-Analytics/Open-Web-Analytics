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
 * First hit tag View
 * 
 * This HTML tag will process the first hit cooki. This tag can only be placed by php
 * as part of the same process that is doing the page logging or else it will create 
 * a race condition.
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_helperPageTagsView extends owa_view {
	
	function owa_helperPageTagsView() {
		
		$this->owa_view();
		$this->priviledge_level = 'guest';
		
		return;
	}
	
	function construct($data) {
		
		$this->body->set('site_id', $this->config['site_id']);
		
		// check for no presence of persistant cookies
		if(empty($data['site_'.$this->config['site_id']])):
			$second_check = true;
		else:
			$second_check = false;
		endif;
		
		if(empty($data[$this->config['visitor_param']])):
			$second_check = true;
		else:
			$second_check = false;
		endif;
		
		if (empty($data[$this->config['first_hit_param'].'_'.$this->config['site_id']]) && $second_check == true):
			
			if ($this->config['delay_first_hit'] == true):
				$this->body->set('first_hit_tag', true);
				//$this->e->debug('adding first hit tag');
			endif;
		endif;
		
		if ($this->config['log_dom_clicks'] == true):
			$this->body->set('click_tag', true);	
		endif;
		
		// load body template
		$this->t->set_template('wrapper_blank.tpl');
		
		// load body template
		$this->body->set_template('js_helper_tags.tpl');
		
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

class owa_helperPageTagsController extends owa_controller {
	
	function owa_helperPageTagsController($params) {
		$this->owa_controller($params);
		$this->priviledge_level = 'guest';
	}
	
	function action() {
		
		// Control logic
		
		if (empty($this->params[$this->config['first_hit_param']]) && 
			empty($this->params[$this->config['visitor_param']])):
		
			$data['view_method'] = 'delegate'; // Delegate, redirect
			$data['view'] = 'base.helperPageTags';		
		endif;
		
		
		// Setup the data array that will be returned to the view.
		
		return $data;
	}
	
	
}

?>