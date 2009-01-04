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
		$this->cache->setNonPersistantCollection('owa_request');
		
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
	 * Persist new object
	 *
	 */ 
	function create() {	
		
		return $this->entity->create();
		
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
	
	/**
	 * Sets Values/Properties
	 * @depricated use setProperties()
	 */
	function setValues($values) {
		
		return $this->entity->setProperties($values);
		
	}
	
	/**
	 * Sets object attributes
	 *
	 * @param unknown_type $array
	 */
	function setProperties($array) {
		
		$this->entity->setProperties($array);
		
		return;
	}
	
	function set($name, $value) {
		
		return $this->entity->$name->value = $value;
	}
	
	function get($name) {
		
		return $this->entity->$name->value;
	}
	
	function _getProperties() {
		
		return $this->entity->_getProperties();
		
	}
	
	function getColumns($return_as_string = false, $as_namespace = '', $table_namespace = false) {
		
		return $this->entity->getColumns($return_as_string, $as_namespace, $table_namespace);
		
	}
	
	function getColumnsSql($as_namespace = '', $table_namespace = true) {
	
		return $this->entity->getColumnsSql($as_namespace, $table_namespace);

	}
	
	/**
	 * 
	 * @depricated
	 */
	function find($params = array()) {
		
		$db = owa_coreAPI::dbSingleton();
		
		$db->selectFrom(get_class($this->entity), $db->removeNs($this->entity->getTableName()));
		
		$values = $this->entity->getColumns();
		
		$primary_obj_ns = $db->removeNs(get_class($this->entity));
		
		foreach ($values as $k => $v) {
			
			if (empty($params['related_objs'])):
				$db->selectColumn($v);
			else:
				$db->selectColumn($primary_obj_ns.'.'.$v, $primary_obj_ns.'_'.$v);
			endif;
			
		}
		
		$db->selectFrom(get_class($this->entity), $ns);

		// add related objects
		if(!empty($params['related_objs'])):
		
			foreach ($params['related_objs'] as $fk => $v_obj) {
			
				$values = $v_obj->entity->getColumns();
				
				$ns = $db->removeNs(get_class($v_obj->entity));
				
				foreach ($values as $k_values => $v_values) {
			
						$db->selectColumn($ns.'.'.$v_values, $ns.'_'.$v_values);
					
				}
				
				$for_key = $primary_obj_ns . '.' . $fk;
				$pk = $ns . '.id';
				
				$db->join(OWA_SQL_JOIN_LEFT_OUTER, get_class($v_obj->entity), $ns, $for_key, $pk);

			}
		
		endif;
		
		
		if(!empty($params['constraints'])):
			foreach ($params['constraints'] as $k_con => $v_con) {
				
				if (is_array($v_con)):
					$db->where($k_con, $v_con['value'], $v_con['operator']);
				else:
					$db->where($k_con, $v_con);
				endif;
			}
		endif;
		
		return $db->getAllRows();
		
	}
	
	/**
	 * 
	 * @depricated
	 */
	function query($params) {
		
		$db = owa_coreAPI::dbSingleton();
		
		$primary_obj_ns = $db->removeNs(get_class($this->entity));
		
		if (!empty($params)):
			if (!empty($this->params)):	
				$params = array_merge($this->params, $params);
			endif;
		endif;
	
		// construct FROM
		$db->selectFrom(get_class($this->entity), $db->removeNs($this->entity->getTableName()));
		$db->selectColumn($params['select']);
		$pns = $db->removeNs($this->entity->getTableName());
		// add related objects
		if(!empty($params['related_objs'])):
		
			foreach ($params['related_objs'] as $fk => $v_obj) {
			
				//$values = $v_obj->entity->getColumns();
				
				$ns = $db->removeNs(get_class($v_obj->entity));
				
				//foreach ($values as $k_values => $v_values) {
			
				//		$db->selectColumn($ns.'.'.$v_values, $ns.'_'.$v_values);
					
				//}
				
				$for_key = $primary_obj_ns . '.' . $fk;
				$pk = $ns . '.id';
				
				$db->join(OWA_SQL_JOIN_LEFT_OUTER, get_class($v_obj->entity), $ns, $for_key, $pk);

			}
		
		endif;

		
		
		if(!empty($params['constraints'])):
			foreach ($params['constraints'] as $k_con => $v_con) {
				
				$db->where($k_con, $v_con['value'], $v_con['operator']);
				
			}
		endif;
						
		// construct GROUP BY
		
		if(!empty($params['groupby'])):
		
			if (is_array($params['groupby'])):
			
				foreach ($params['groupby'] as $groupby) {
			
					$db->groupBy($groupby);
			
				}
			else:
				$db->groupBy($params['groupby']);
			endif;
		
		endif;
		
		// construct ORDER
		
		if(!empty($params['orderby'])):
		
			if (is_array($params['orderby'])):
			
				foreach ($params['orderby'] as $orderby) {
			
					$db->groupBy($orderby);
			
				}
			else:
				$db->orderBy($params['groupby']);
			endif;
		
		endif;
		
		if(!empty($params['order'])):
			$db->order($params['order']);
		endif;
		
		// construct LIMIT
		
		if(!empty($params['limit'])):
			$db->limit($params['order']);	
		endif;
		
		// construct OFFSET
		
		if(!empty($params['offset'])):
			$db->offset($params['offset']);
		endif;
		
		if(!empty($params['result_format'])):
			$db->setFormat($params['result_format']);
		endif;

		return $db->getAllRows();
	
		
		
	}
	
	/**
	 * 
	 * @depricated
	 */
	function addRelatedObject($foreign_key, $obj) {
	
		return $this->params['related_objs'][$foreign_key] = $obj;
	
	}
	
	/**
	 * 
	 * @depricated
	 */
	function addConstraint($col, $value) {
	
		return $this->params['constraints'][$col] = $value;
	
	}
	
	/**
	 * 
	 * @depricated
	 */
	function addGroupBy($col) {
		
		return $this->params['groupby'][] = $col;
	}
	
	/**
	 * 
	 * @depricated
	 */
	function addOrderBy($col) {
		
		return $this->params['orderby'][] = $col;
	}
	
	/**
	 * 
	 * @depricated
	 */
	function setOrder($flag) {
		
		return $this->params['order'] = $flag;
	}
	
	/**
	 * 
	 * @depricated
	 */
	function setSelect($string) {
		
		return $this->params['select'] = $string;
	
	}
	
	
	function setGuid($string) {
		
		return owa_lib::setStringGuid($string);
		
	}
	
	function getTableName() {
	
		return $this->entity->getTableName();
	
	}
	
}

?>