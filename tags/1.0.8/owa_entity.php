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

if (!class_exists('owa_dbColumn')):
	require_once(OWA_BASE_CLASS_DIR.'column.php');
endif;

/**
 * Abstract Entity Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_entity {
	
	function owa_entity() {
		
		$vars = $this->getColumns();
		
		foreach ($vars as $k => $v) {
			
			$this->$v = new owa_dbColumn();
		}
		
		return;
	}
	
	function _getProperties() {
		
		$vars = get_object_vars($this);
		
		$properties = array();
		
		foreach ($vars as $k => $v) {
			
			$properties[$k] = $v->value;
			
		}
		
		return $properties;	
	}
	
	function getColumns() {
		
		$all_cols = get_object_vars($this);
		
		return array_keys($all_cols);
		
	}
	
	/**
	 * Sets object attributes
	 *
	 * @param unknown_type $array
	 */
	function setProperties($array) {
		
		$properties = $this->getColumns();
		
		foreach ($properties as $k => $v) {
				
				if (!empty($array[$v])):
					$this->$v->value = $array[$v];
				endif;
				
			}
		
		return;
	}
	
	function setGuid($string) {
		
		return owa_lib::setStringGuid($string);
		
	}
	
	function set($name, $value) {
		
		return $this->$name->value = $value;
	}
	
	
	function setValues($values) {
		
		$properties = array_keys(get_object_vars($this));
		
		foreach ($properties as $k => $v) {
				
				$this->$v->value = $values[$v];
		
			}
		
		return;
		
	}
	
	function get($name) {
		
		return $this->$name->value;
	}
	
}

?>