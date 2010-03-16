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
	
		return owa_helperPageTagsController::__construct($params);
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function action() {
		
		// Control logic
		
		// check to see if first hit tag is needed
		if (owa_coreAPI::getSetting('base', 'delay_first_hit')) {
		
			$service = &owa_coreAPI::serviceSingleton();
			//check for persistant cookie
			$v = $service->request->getOwaCookie('v');
			
			if (empty($v)) {
				
				$this->set('first_hit_tag', true);
			}		
		}
		
		// check to see if clicktag is needed
		if (owa_coreAPI::getSetting('base', 'log_dom_clicks')) {
			
			$this->set('click_tag', true);
		}
		
		// site id needed for link state
		$this->set('site_id', owa_coreAPI::getSetting('base', 'site_id'));
		
		$this->setView('base.helperPageTags');
		
		return;
	}
	
}


/**
 * Helper page tags
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
		
		return owa_helperPageTagsView::__construct();
	}
	
	function __construct() {
	
		return parent::__construct();
	}
	
	function render($data) {
		
		$this->body->set('site_id', $this->get('site_id'));
		
		// will include the first ht tracking tag
		if ($this->get('first_hit_tag')) {
			$this->body->set('first_hit_tag', true);
		}
		
		// do not log pageview via js as it was already logged via PHP
		$this->body->set('do_not_log_pageview', true);
		
		//check to see if we shuld log clicks.
		if (!owa_coreAPI::getSetting('base', 'log_dom_clicks')) {
			$this->body->set('do_not_log_clicks', true);
		} else {
			$this->body->set('do_not_log_clicks', false);
		}
		
		// check to see if we should log clicks.
		if (!owa_coreAPI::getSetting('base', 'log_dom_streams')) {
			$this->body->set('do_not_log_domstream', true);
		} else {
			$this->body->set('do_not_log_domstream', false);
		}
		
		if (owa_coreAPI::getSetting('base', 'is_embedded')) {
		
			// needed to override the endpoint used by the js tracker
			$this->body->set('endpoint', owa_coreAPI::getSetting('base', 'log_url'));
			
			// needed to override the endpoint used by the js tracker
			$this->body->set('apiEndpoint', owa_coreAPI::getSetting('base', 'action_url'));
		} else {
			// needed to override the endpoint used by the js tracker
			$this->body->set('endpoint', '');
			
			// needed to override the endpoint used by the js tracker
			$this->body->set('apiEndpoint', '');
		}
		
		// load body template
		$this->t->set_template('wrapper_blank.tpl');
		
		// load body template
		$this->body->set_template('js_helper_tags.tpl');
		
		return;
	}
	
	
}

?>