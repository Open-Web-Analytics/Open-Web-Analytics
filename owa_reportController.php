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

require_once(OWA_BASE_CLASSES_DIR.'owa_controller.php');

/**
 * Report Controller Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_reportController extends owa_controller {
	
	/**
	 * Constructor
	 *
	 * @param array $params
	 * @return owa_controller
	 */
	function owa_reportController($params) {
		
		$this->owa_controller($params);
		
		return;
		
	}
	
	/**
	 * Handles request from caller
	 *
	 */
	function doAction() {
		
		$this->e->debug('Performing Action: '.get_class($this));
		
		if (empty($this->params['site_id'])):
			$this->params['site_id'] = $this->config['site_id'];
		endif;
		
		// set default period if necessary
		if (empty($this->params['period'])):
			$this->params['period'] = 'today';
		endif;
		
		
		return $this->action();
		
	}
	
}

?>