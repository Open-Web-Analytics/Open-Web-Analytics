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


class owa_widgetController extends owa_controller {
	
	function owa_widgetController($params) {
		
		$this->owa_controller($params);
		$this->priviledge_level = 'viewer';
	
	}
	
	function action() {
		
		$data = array();
		$data['params'] = $this->params;
		
		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
		
		$data['widget'] = $this->params['widget'];
		$data['format'] = $this->params['format'];
		$data['width'] = $this->params['width'];
		$data['height'] = $this->params['height'];
		
		// flag used to pick the right wrapper template
		if (array_key_exists('is_external', $this->params)):
			$data['is_external'] = $this->params['is_external'];
		endif;
		$data['view'] = 'base.widget';
		$data['view_method'] = 'delegate';
		
		return $data;
	}
}




/**
 * Widget  View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_widgetView extends owa_view {
	
	function owa_widgetView() {
		
		$this->owa_view();
		
		return;
	}
	
	function construct($data) {
		
		// load template
		
		if ($data['is_external'] == true):
			$this->t->set_template('wrapper_widget.tpl');
		else:
			$this->t->set_template('wrapper_blank.tpl');
		endif;
		
		if (!array_key_exists('width', $data)):
			$data['width'] = 300;
		endif;
		
		if (!array_key_exists('width', $data)):
			$data['height'] = 250;
		endif;
		
		$this->body->set_template('widget.tpl');
		$this->body->set('format', $data['format']);
		$this->body->set('widget', $data['widget']);			
		$this->body->set('params', $data['params']);	
		return;
	}
	
	
}


?>