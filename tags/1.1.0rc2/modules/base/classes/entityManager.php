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



/**
 * Entity Manager
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_entityManager extends owa_base {

	var $entity;
	var $from_cache;
	var $cache;
	var $db;
	var $params;

	function __construct($entity_name) {
		//print "hello from emanager constructor";
		$this->owa_base();
		$this->db = &owa_coreAPI::dbSingleton();
		$this->cache = &owa_coreAPI::cacheSingleton(); 
		$this->cache->setCacheDir(OWA_CACHE_DIR);
		$this->cache->setNonPersistantCollection('owa_session');
		
		if (!class_exists('owa_entity')):
			require_once(OWA_BASE_CLASSES_DIR.'owa_entity.php');	
		endif;
			
		$this->entity = owa_coreAPI::moduleSpecificFactory($entity_name, 'entities', '', '', false);
		
		return;
	}
	
	function __destruct() {
	
		return;
	}
	
	function owa_entityManager($entity_name) {
		
		return $this->__construct($entity_name);
		
	}
	
	function _getProperties() {
		
		$vars = get_object_vars($this->entity);
		
		$properties = array();
		
		foreach ($vars as $k => $v) {
			
			$properties[$k] = $v->value;
			
		}
		
		return $properties;	
	}
	
	function getColumns() {
		
		$all_cols = get_object_vars($this->entity);
		
		return array_keys($all_cols);
		
	}
	
	/**
	 * Persist new object
	 *
	 */ 
	function create() {	
		
		$all_cols = $this->entity->getColumns();
		
		$cols = '';
		// Control loop
		foreach ($all_cols as $k => $v){
		
			// drop column is it is marked as auto-incement as DB will take car of that.
			if ($this->entity->$v->auto_increment == true):
				break;
			else:
				$cols[$v] = $this->entity->$v->value;
			endif;
				
		}
	
		// Persist object
		$status = $this->db->save($cols, get_class($this->entity));
		
		// Add to Cache
		
		if ($status == true):
			if ($this->config['cache_objects'] == true):
				$this->cache->set(get_class($this->entity), 'id'.$this->entity->id->value, $this->entity);
			endif;
		endif;
		
		return $status;
		
	}
	
	/**
	 * Update all properties of an Existing object
	 *
	 */
	function update($where = '') {	
		
		if(empty($where)):
			$constraint = array('id' => $this->entity->id->value);
		else:
			$constraint = array($where => $this->entity->$where->value);
		endif;
		
		
		// Persist object
		$status = $this->db->update($this->_getProperties(), $constraint, get_class($this->entity));
		
		// Add to Cache
		if ($status == true):
			if ($this->config['cache_objects'] == true):
				$this->cache->replace(get_class($this->entity), 'id'.$this->entity->id->value, $this->entity);
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
		
		$properties = array();
		
		foreach ($named_properties as $n) {
			
			$properties[$n] = $this->entity->$n->value;
			
		}
		
				
		// Persist object
		$status = $this->db->update($properties, $where, get_class($this->entity));
		
		// Add to Cache
		if ($status == true):
			if ($this->config['cache_objects'] == true):
				$this->cache->set(get_class($this->entity), 'id'.$this->entity->id->value, $this->entity);
			endif;
		endif;
		
		return $status;
		
	}
	
	
	/**
	 * Delete Object
	 *
	 */
	function delete($id, $col = '') {	
				
		if (empty($col)):
			$col = 'id';
		endif;
		
		// Persist object
		$status = $this->db->delete($id, $col, get_class($this->entity));
	
		// Add to Cache
		if ($status == true):
			if ($this->config['cache_objects'] == true):
				$this->cache->remove(get_class($this->entity), 'id'.$this->entity->id->value);
			endif;
		endif;
		
		return $status;
		
	}
	
	function getByPk($col, $value) {
		
		return $this->getByColumn($col, $value);
		
	}
	
	function getByColumn($col, $value) {
		
		$cache_obj = '';
		
		if ($this->config['cache_objects'] == true):
			$cache_obj = $this->cache->get(get_class($this->entity), $col.$value);
		endif;
			
		if (!empty($cache_obj)):
		
			$this->entity = $cache_obj;
					
		else:
		
			$constraint = array($col => $value);
				
			$properties = $this->db->select($this->_getProperties(), $constraint, get_class($this->entity));
			
			if (!empty($properties)):
					
				$this->setProperties($properties);
				
				if ($this->config['cache_objects'] == true):
					$this->cache->set(get_class($this->entity), 'id'.$this->entity->id->value, $this->entity);	
				endif;		
			endif;
		endif;
		
		return; 
	}
	
	function find($params = array()) {
		
		
		$params['primary_obj'] = $this->entity;
		
		return $this->db->getObjs($params);
		
	}
	
	function query($params) {
		
		$this->params['primary_obj'] = $this->entity;
		
		if (!empty($params)):
			return $this->db->selectQuery(array_merge($this->params, $params));
		else:
			return $this->db->selectQuery($this->params);
		endif;
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
					$this->entity->$v->value = $array[$v];
				endif;
				
			}
		
		return;
	}
	
	function setGuid($string) {
		
		return owa_lib::setStringGuid($string);
		
	}
	
	function set($name, $value) {
		
		return $this->entity->$name->value = $value;
	}
	
	
	function setValues($values) {
		
		$properties = array_keys(get_object_vars($this->entity));
		
		foreach ($properties as $k => $v) {
				
				$this->entity->$v->value = $values[$v];
		
			}
		
		return;
		
	}
	
	function get($name) {
		
		return $this->entity->$name->value;
	}
	
	function addRelatedObject($foreign_key, $obj) {
	
		return $this->params['related_objs'][$foreign_key] = $obj;
	
	}
	
	function addConstraint($col, $value) {
	
		return $this->params['constraints'][$col] = $value;
	
	}
	
	function addGroupBy($col) {
		
		return $this->params['groupby'][] = $col;
	}
	
	function addOrderBy($col) {
		
		return $this->params['orderby'][] = $col;
	}
	
	function setOrder($flag) {
		
		return $this->params['order'] = $flag;
	}
	
	function setSelect($string) {
		
		return $this->params['select'] = $string;
	
	}
	
}

?>