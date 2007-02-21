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
require_once (OWA_BASE_CLASSES_DIR .'owa_base.php');
require_once (OWA_BASE_CLASSES_DIR .'owa_coreAPI.php');
require_once (OWA_JPGRAPH_DIR .'jpgraph.php');

/**
 * Abstract jp graph View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_abstractJpGraphView extends owa_base {
	
	var $data;
	
	var $graph;
	
	var $api;
	
	function owa_abstractJpGraphView($params = array()) {
		
		$this->owa_base();
		ob_clean();
		
		return;
	}
	
	function construct() {
		
		return false;
	}
	
	function assembleView($data) {
		
		$this->data = $data; 
	
		$this->e->debug('Assembling view: '.get_class($this));
		
		// creates graph object and assembles caller params.
		$this->construct($this->data);
	
		// creates JP graph objects and sets properties.
		$this->graph->construct();	
		
		// outputs the graph
	
		$this->graph->graph->Stroke();
		
		//debug_print_backtrace();
		
		return;
	
	}
	
	function makeDateArray($result, $format) {
		
		$timestamps = array();
			
			foreach ($result as $row) {
				
				$timestamps[]= mktime(0,0,0,$row['month'],$row['day'],$row['year']);
				
			}
		
		return $this->makeDates($timestamps, $format);
	}
	
	function makeDates($timestamps, $format) { 
		
		sort($timestamps);
			
			$new_dates = array();
			
			foreach ($timestamps as $timestamp) {
				
				$new_dates[] = date($format, $timestamp);
				
			}
			
		return $new_dates;
		
	}
}

?>