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

require_once(OWA_BASE_DIR.'/owa_controller.php');
require_once(OWA_BASE_DIR.'/owa_view.php');


/**
 * Get Individual Result Set Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */

class owa_getResultSetController extends owa_controller {
	
	function __construct($params) {
		
		$this->setRequiredCapability('view_reports');
		parent::__construct($params);
	}
	
	function action() {
		
		$rs = owa_coreAPI::getResultSet($this->getAllParams());		
		
		$this->setView('base.getResultSet');
		$this->set('result_set', $rs);
		
		// set format
		if ($this->get('format')) {
			$this->set('format', $this->getParam('format'));
		}
	}
	
}

class owa_getResultSetView extends owa_view {
	
	function render() {
	
		$this->t->setTemplateFile('base', 'wrapper_blank.tpl');
		$this->body->setTemplateFile('base', 'wrapper_blank.tpl');
		
		$rs = $this->get('result_set');
		$format = $this->get('format');
		
		if (!$format) {
			$format = 'html';		
		}

		$this->setContentTypeHeader($format);
		echo $rs->formatResults($format);
		
	}
}

?>