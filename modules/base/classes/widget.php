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
	var $dom_id;
	
	/**
	 * holding tank or metrics that need 
	 * to be shared between action methods
	 */
	var $metrics = array();
	
	function __construct($params) {
		
		$this->type = 'widget';
		//$this->setRequiredCapability('view_reports');
		//print_r($params);
		return parent::__construct($params);
	}

	function pre() {
	
	
		$this->setPeriod($this->getParam('period'));
		
		// create dom safe id from do action param
		$this->dom_id = str_replace('.', '-', $this->params['do']);
		$this->data['dom_id'] = $this->dom_id;
			
		if (!array_key_exists('format', $this->params)):
			
				$this->params['format'] = $this->default_format;
		
		else:
			if (empty($this->params['format'])):
				$this->params['format'] = $this->default_format;
			endif;
		endif;
		
		return;
	}
	
	function post() {
	
		// calls widget format specific functions
		
		$this->doFormatAction($this->params['format']);
	
		// used to add outer wrapper to widget if it's the first view.
		$iv = $this->getParam('initial_view');
		if ($iv == true):
			$this->data['subview'] = $this->data['view'];
			$this->data['view'] = 'base.widget';
			// we dont want to keep passing this.
			unset($this->data['params']['initial_view']);
		endif;
		
		
		$this->data['wrapper'] = $this->getParam('wrapper');
		$this->data['widget'] = $this->params['do'];
		$this->data['do'] = $this->params['do'];
		
		// set default dimensions
		
		if (array_key_exists('width', $this->params)):
			$this->setWidth($this->params['width']);
		endif;
		
		if (array_key_exists('height', $this->params)):
			$this->setHeight($this->params['height']);
		endif;

	}
	
	function enableFormat($name, $label = '') {
		
		if (empty($label)):
			$label = ucwords($name);
		endif;
		
		$this->data['widget_views'][$name] = $label;
		return;
	
	}
	
	function setHeight($height) {
		
			$this->data['height'] = $height;
		
		return;
	}
	
	function setWidth($width) {
	
		$this->data['width'] = $width;
		
		return;
	}
	
	function setDefaultFormat($format) {
	
		$this->default_format = $format;
		
		return;
		
	}
	
	function doFormatAction($format = '') {
	
	
		$method = $this->params['format'].'Action';
			
		if (method_exists($this, $method)) {
			$this->$method();
		} else {
			$this->e->debug("Widget format not implemented. No method named $method");
		}
	
	}
	
	function setMetric($name, $obj) {
		$this->metrics[$name] = $obj;
		return;
	}
	
	function getMetric($name) {
		return $this->metrics[$name];
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
	
	function __construct() {
		
		return parent::__construct();
	}
	
	function render($data) {
		
		// load template
		
		if (array_key_exists('is_external', $data['params'])):
			if ($data['params']['is_external'] == true):
				$this->t->set_template('wrapper_widget.tpl');
			else:
				$this->t->set_template('wrapper_blank.tpl');
			endif;
		else:
			$this->t->set_template('wrapper_blank.tpl');
		endif;
		
		if (array_key_exists('width', $data)):
			$data['params']['width'] = $data['width'];
		endif;
		
		if (array_key_exists('height', $data)):
			$data['params']['height'] = $data['height'];
		endif;
		
		$this->_setLinkState();
		
		if ($data['wrapper'] === true):
			$this->body->set_template('widget.tpl');
		elseif ($data['wrapper'] === 'inpage'):
			$this->body->set_template('widget_inpage.tpl');
		endif;
		
		if (array_key_exists('format', $data['params'])):
			$this->body->set('format', $data['params']['format']);
		endif;
		
		$this->body->set('widget', str_replace('.', '-', $data['widget']));			
		$this->body->set('params', $data['params']);	
		$this->body->set('title', $data['title']);
		$this->body->set('widget_views', $data['widget_views']);
		$this->body->set('widget_views_count', count($data['widget_views']));
		$this->body->set('do', $data['widget']);
		
		return;
	}
	
	
}


	

?>