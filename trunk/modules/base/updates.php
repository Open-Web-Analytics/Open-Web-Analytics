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
 * Update View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_updatesView extends owa_view {
		
	function render($data) {
		
		//switch wrapper if OWA is not embedded
		// needed becasue this view might be rendered before anything else.
		if (isset($this->config['is_embedded']) && $this->config['is_embedded'] != true) {
			$this->t->set_template('wrapper_public.tpl');
		}
		
		$this->body->set_template('updates.tpl');// This is the inner template
		$this->body->set('headline', 'Your database needs to be upgraded...');
		$this->body->set('modules', $data['modules']);
	}
}

class owa_updatesController extends owa_controller {
	
	function action() {
		
		$data = array();
				
		$data['view_method'] = 'delegate';
		$data['view'] = 'base.updates';
		$data['modules'] = owa_coreAPI::getModulesNeedingUpdates();
		
		return $data;
	}
}

?>
