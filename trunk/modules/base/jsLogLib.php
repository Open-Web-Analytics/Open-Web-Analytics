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
 * Javascript Page View Tracking Library View
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
		
		
		/*
$request = http_get_request_headers();  
		if (isset($request['If-Modified-Since']))  
		{  
		 $modifiedSince = explode(';', $request['If-Modified-Since']);  
		 $modifiedSince = strtotime($modifiedSince[0]);  
		}  
		else  
		{  
		 $modifiedSince = 0;  
		}
		
		if ($lastModified <= $modifiedSince)  {  
			header('HTTP/1.1 304 Not Modified');  
 			exit(); 
		}
*/
	
		// load body template
		$this->t->set_template('wrapper_blank.tpl');
	
		$this->body->set('log_pageview', true);
		
		if (owa_coreAPI::getSetting('base', 'log_dom_clicks')) {
			$this->body->set('log_clicks', true);
		}
		
		// load body template
		$this->body->set_template('js_logger.tpl');
		// load body template
		$this->setJs('json2', 'base/js/includes/json2.js');
		$this->setJs('lazyload', 'base/js/includes/lazyload-2.0.min.js');
		$this->setJs('owa', 'base/js/owa.js');
		//$this->setJs('url_encode', 'base/js/includes/url_encode.js');
		$this->setJs('owa.tracker', 'base/js/owa.logger.js');
		$this->concatinateJs();
		/*
		header('Cache-Control: public');
		header('Expires: ' . gmdate('D, d M Y H:i:s', time()+24*60*60*3000) . ' GMT');
		header('ETag: xyzzy');
		header('Pragma: ');
		header('Last-modified: '.gmdate('D, d M Y H:i:s', time()-24*60*60*30) . ' GMT');
		*/
		
		return;
	}
	
	
}




?>