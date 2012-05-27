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



class owa_jsLogLibController extends owa_controller {


	function __construct($params) {
	
		return parent::__construct($params);
	
	}
	
	function owa_jsLogLibController($params) {
		
		return owa_jsLogLibController::__construct($params);
	}
	
	function action($data) {
	
		$this->setView('base.jsLogLibView');
		
		return;
	
	}

}

/**
 * Combined Javascript Tracker Library and Invocation view
 *
 * Returns owa.tracker lib and invocation as a non minimized contatinated stream. This method
 * has been depricated in favor of a static file approach and is maintained
 * solely for backwards compatability with old style tags.
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_jsLogLibView extends owa_view {
	
	function owa_jsLogLibView() {
				
		return owa_jsLogLibView::__construct();
	}
	
	function __construct() {
		
		return parent::__construct();
	}
	
	function render($data) {
	
		// load body template
		$this->t->set_template('wrapper_blank.tpl');
		
		// check to see if we should log clicks.
		if (!owa_coreAPI::getSetting('base', 'log_dom_clicks')) {
			$this->body->set('do_not_log_clicks', true);
		}
		
		// check to see if we should log clicks.
		if (!owa_coreAPI::getSetting('base', 'log_dom_stream')) {
			$this->body->set('do_not_log_domstream', true);
		}
		
		//set siteId variable name to support old style owa_params js object
		$this->body->set("site_id", "owa_params['site_id']");
		// set name of javascript object containing params that need to be logged
		// depricated, but needed to support old style tags
		$this->body->set("owa_params", true);
		// load body template
		$this->body->set_template('js_logger.tpl');
		
		// assemble JS libs
		$this->setJs('json2', 'base/js/includes/json2.js');
		$this->setJs('lazyload', 'base/js/includes/lazyload-2.0.min.js');
		$this->setJs('owa', 'base/js/owa.js');
		$this->setJs('owa.tracker', 'base/js/owa.tracker.js');
		//$this->setJs('url_encode', 'base/js/includes/url_encode.js');
		$this->concatinateJs();
		
		
		return;
	}
	
	
}




?>