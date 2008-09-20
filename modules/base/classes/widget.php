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
require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_view.php');

/**
 * Abstract Widget Controller Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_widgetController extends owa_controller {
	
	var $default_format = 'graph';
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	function owa_widgetController($params) {
	
		return owa_widgetController::__construct($params);
	}
	
	function pre() {
	
		if (!array_key_exists('format', $this->params)):
			$this->params['format'] = $this->default_format;
		endif;
		
		return;
	}
	
	function post() {
	
		// used to add outer wrapper to widget if it's the first view.
		if ($this->params['initial_view'] == true):
			$this->data['subview'] = $this->data['view'];
			$this->data['view'] = 'base.widget';
			// we dont want to keep passing this.
			unset($this->data['params']['initial_view']);
		endif;
		
		$this->data['widget'] = $this->params['do'];
	
	}
	
	function enableFormat($name, $label) {
	
		$this->data['widget_views'][$name] = $label;
		return;
	
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
		
		if ($data['params']['is_external'] == true):
			$this->t->set_template('wrapper_widget.tpl');
		else:
			$this->t->set_template('wrapper_blank.tpl');
		endif;
		
		if (!array_key_exists('width', $data)):
			$data['params']['width'] = 300;
		endif;
		
		if (!array_key_exists('width', $data)):
			$data['params']['height'] = 250;
		endif;
		
		$this->body->set_template('widget.tpl');
		$this->body->set('format', $data['params']['format']);
		$this->body->set('widget', str_replace('.', '-', $data['widget']));			
		$this->body->set('params', $data['params']);	
		$this->body->set('title', $data['title']);
		$this->body->set('widget_views', $data['widget_views']);
		$this->body->set('do', $data['widget']);	
		
		return;
	}
	
	
}


	

?>