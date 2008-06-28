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
		
		return $this->entity->create();
		
	}
	
	/**
	 * Create Table
	 *
	 * Handled by DB abstraction layer because the SQL associated with this is way too DB specific
	 */
	function createTable() {
		
		// Persist table
		$status = $this->db->createTable($this->entity);
		
		if ($status == true):
			$this->e->notice(sprintf("%s Table Created.", get_class($this->entity)));
			return true;
		else:
			$this->e->notice(sprintf("%s Table Creation Failed.", get_class($this->entity)));
			return false;
		endif;
	
	}
	
	/**
	 * DROP Table
	 *
	 * Drops a table. will throw error is table does not exist
	 */
	function dropTable() {
		
		// Persist table
		$status = $this->db->dropTable(get_class($this->entity));
		
		if ($status == true):
			return true;
		else:
			return false;
		endif;
	
	}
	
	function addColumn($column_name) {
		
		// Persist table
		$status = $this->db->addColumn(get_class($this->entity), $column_name, $this->entity->$column_name->getDefinition());
		
		if ($status == true):
			return true;
		else:
			return false;
		endif;
		
	}
	
	function dropColumn($column_name) {
		
		$status = $this->db->dropColumn(get_class($this->entity), $column_name);
		
		if ($status == true):
			return true;
		else:
			return false;
		endif;		
		
	}
	
	function modifyColumn($column_name) {
	
		$status = $this->db->modifyColumn(get_class($this->entity), $column_name, $this->entity->$column_name->getDefinition());
		
		if ($status == true):
			return true;
		else:
			return false;
		endif;		
	
	
	}
	
	function renameColumn($old_column_name, $column_name) {
	
		$status = $this->db->renameColumn(get_class($this->entity), $old_column_name, $column_name);
		
		if ($status == true):
			return true;
		else:
			return false;
		endif;		
		
	}
	
	function renameTable($new_table_name) {
	
		$status = $this->db->renameTable(get_class($this->entity), $new_table_name);
		
		if ($status == true):
			return true;
		else:
			return false;
		endif;		
		return;
	}
	
	
	
	/**
	 * Update all properties of an Existing object
	 *
	 */
	function update($where = '') {	
		
		$this->entity->update($where);
		
	}
	
	/**
	 * Update named list of properties of an existing object
	 *
	 * @param array $named_properties
	 * @param array $where
	 * @return boolean
	 */
	function partialUpdate($named_properties, $where) {
		
		return $this->entity->partialUpdate($named_properties, $where);
		
	}
	
	
	/**
	 * Delete Object
	 *
	 */
	function delete($id, $col = '') {	
				
		return $this->entity->delete($id, $col);	
	}
	
	function getByPk($col, $value) {
		
		return $this->entity->getByColumn($col, $value);
		
	}
	
	function getByColumn($col, $value) {
		
		return $this->entity->getByColumn($col, $value);
		
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
	
	/**
	 * Sets Values/Properties
	 * @depricated use setProperties()
	 */
	function setValues($values) {
		
		return $this->setProperties($values);
		
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