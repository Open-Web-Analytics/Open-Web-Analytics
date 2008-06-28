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
			
			$this->$v = new owa_dbColumn($this->$v);
			$this->$v->set('name', $v);
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
	
	function getTableOptions() {
	
		return array('table_type' => 'disk');
	
	}
	
	/**
	 * Persist new object
	 *
	 */ 
	function create() {	
		
		$db = owa_coreAPI::dbSingleton();
		$config = owa_coreAPI::configSingleton();
		$cache = owa_coreAPI::cacheSingleton();
		
		$all_cols = $this->getColumns();
		
		$cols = array();
		
		// Control loop
		foreach ($all_cols as $k => $v){
		
			// drop column is it is marked as auto-incement as DB will take care of that.
			if ($this->$v->auto_increment == true):
				;
			else:
				$cols[$v] = $this->$v->value;
			endif;
				
		}
	
		// Persist object
		$status = $db->save($cols, get_class($this));
		
		// Add to Cache
		
		if ($status == true):
			if ($config->get('base', 'cache_objects') == true):
				$cache->set(get_class($this), 'id'.$this->id->value, $this);
			endif;
		endif;
		
		return $status;
		
	}
	
	/**
	 * Update all properties of an Existing object
	 *
	 */
	function update($where = '') {	
		
		$db = owa_coreAPI::dbSingleton();
		$config = owa_coreAPI::configSingleton();
		$cache = owa_coreAPI::cacheSingleton();
		
		if(empty($where)):
			$constraint = array('id' => $this->id->value);
		else:
			$constraint = array($where => $this->$where->value);
		endif;
		
		
		// Persist object
		$status = $db->update($this->_getProperties(), $constraint, get_class($this));
		
		// Add to Cache
		if ($status == true):
			if ($config->get('base', 'cache_objects') == true):
				$cache->replace(get_class($this), 'id'.$this->id->value, $this);
			endif;
		endif;
		
		return $status;
		
	}
	
	/**
	 * Update named list of properties of an existing object
	 *
	 * @param array $named_properties
	 * @param array $where
	 * @return boolean
	 */
	function partialUpdate($named_properties, $where) {
		
		$db = owa_coreAPI::dbSingleton();
		$config = owa_coreAPI::configSingleton();
		$cache = owa_coreAPI::cacheSingleton();
		
		$properties = array();
		
		foreach ($named_properties as $n) {
			
			$properties[$n] = $this->$n->value;
			
		}
		
				
		// Persist object
		$status = $db->update($properties, $where, get_class($this));
		
		// Add to Cache
		if ($status == true):
			if ($config->get('base', 'cache_objects') == true):
				$cache->set(get_class($this), 'id'.$this->id->value, $this);
			endif;
		endif;
		
		return $status;
		
	}
	
	
	/**
	 * Delete Object
	 *
	 */
	function delete($id, $col = '') {	
		
		$db = owa_coreAPI::dbSingleton();
		$config = owa_coreAPI::configSingleton();
		$cache = owa_coreAPI::cacheSingleton();
	
		if (empty($col)):
			$col = 'id';
		endif;
		
		// Persist object
		$status = $db->delete($id, $col, get_class($this));
	
		// Add to Cache
		if ($status == true):
			if ($config->get('base', 'cache_objects') == true):
				$cache->remove(get_class($this), 'id'.$this->id->value);
			endif;
		endif;
		
		return $status;
		
	}
	
	function load($value, $col = 'id') {
		
		return $this->getByColumn($col, $value);
		
	}
	
	function getByPk($col, $value) {
		
		return $this->getByColumn($col, $value);
		
	}
	
	function getByColumn($col, $value) {
		
		$db = owa_coreAPI::dbSingleton();
		$config = owa_coreAPI::configSingleton();
		$cache = owa_coreAPI::cacheSingleton();
		
		$cache_obj = '';
		
		if ($config->get('base', 'cache_objects') == true):
			$cache_obj = $cache->get(get_class($this), $col.$value);
		endif;
			
		if (!empty($cache_obj)):
		
			$cache_obj_properties = $cache_obj->_getProperties();
			$this->setProperties($cache_obj_properties);
					
		else:
		
			$constraint = array($col => $value);
				
			$properties = $db->select($this->_getProperties(), $constraint, get_class($this));
			
			if (!empty($properties)):
					
				$this->setProperties($properties);
				
				if ($config->get('base', 'cache_objects') == true):
					$cache->set(get_class($this), 'id'.$this->id->value, $this);	
				endif;		
			endif;
		endif;
		
		return; 
	}



	
}

?>