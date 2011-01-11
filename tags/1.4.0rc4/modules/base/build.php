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

require (OWA_INCLUDE_DIR.'jsmin-1.1.1.php');
require_once(OWA_BASE_CLASS_DIR.'cliController.php');

/**
 * Build Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_buildController extends owa_cliController {
	
	function __construct($params) {
		
		parent::__construct($params);
		
		$this->setRequiredCapability('edit_modules');
		
		return;
	}
	
	function action() {
		
		
		// build owa.tracker-combined-min.js
		owa_coreAPI::debug("Building owa.tracker-combined-min.js");
		
		$tracker_js = array();
		$tracker_js['json2'] = OWA_MODULES_DIR.'base/js/includes/json2.js';
		$tracker_js['lazyload'] = OWA_MODULES_DIR.'base/js/includes/lazyload-2.0.min.js';
		$tracker_js['owa'] = OWA_MODULES_DIR.'base/js/owa.js';
		$tracker_js['owa.tracker'] = OWA_MODULES_DIR.'base/js/owa.tracker.js';
		
		$minjs = sprintf("// OWA Tracker Min file created %s \n\n",date(time()));
		
		foreach ($tracker_js as $k => $v) {
			owa_coreAPI::debug("Minimizing Javascript in $v");
			$minjs .= "//// Start of $k //// \n";
			$minjs .= JSMin::minify(file_get_contents($v)) . "\n";
			$minjs .= "//// End of $k //// \n";		
		}
			
		$handle = fopen(OWA_MODULES_DIR."base/js/owa.tracker-combined-min.js", "w");
		fwrite($handle, $minjs);
		fclose($handle);
		
		return;
	}
		
}


?>