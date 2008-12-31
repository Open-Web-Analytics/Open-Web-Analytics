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

require_once(OWA_BASE_CLASSES_DIR.'owa_adminController.php');

/**
 * Abstract Report Controller Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_reportController extends owa_adminController {
	
	/**
	 * Constructor
	 *
	 * @param array $params
	 * @return
	 */
	function __construct($params) {
	
		$this->setControllerType('report');
		$this->_setCapability('view_report');
		return parent::__construct($params);
	
	}
	
	// PHP 4 style constructor
	function owa_reportController($params) {
		
		return owa_reportController::__construct($params);
		
	}
	
	/**
	 * pre action
	 *
	 */
	function pre() {
		
		// pass full set of params to view
		$this->data['params'] = $this->params;
				
		// set default period if necessary
		if (empty($this->params['period'])):
			$this->params['period'] = 'today';
		endif;
		
		$this->setPeriod($this->getParam('period'));
		
		$this->setView('base.report');
		$this->setViewMethod('delegate');
		
		$this->dom_id = str_replace('.', '-', $this->params['do']);
		$this->data['dom_id'] = $this->dom_id;
		$this->data['do'] = $this->params['do'];
		
		return;
		
	}
	
	function setTitle($title) {
		
		$this->data['title'] = $title;
		return;
	}
}

?>