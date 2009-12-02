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
	
	var $properties = array();
	var $_tableProperties = array();
	
	function __construct() {
		
		// for old style entities
		if (empty($this->properties)) {
			$vars = $this->getColumns();
		
			foreach ($vars as $k => $v) {
				
				$this->$v = new owa_dbColumn($this->$v);
				$this->$v->set('name', $v);
			}
		}
			
		return;
	}
		
	function owa_entity() {
		
		return owa_entity::__construct();
	}
	
	function _getProperties() {
		
		$properties = array();
		
		if (!empty($this->properties)) {
			$vars = $this->properties;
		} else {
			//needed for backwards compatability
			$vars = get_object_vars($this);
			unset($vars['_tableProperties']);
			unset($vars['properties']);
		}
		
		foreach ($vars as $k => $v) {
			
			$properties[$k] = $v->getValue();
				
		}

		return $properties;	
	}
	
	function getColumns($return_as_string = false, $as_namespace = '', $table_namespace = false) {
		
		if (!empty($this->properties)) {
			$all_cols = array_keys($this->properties);
			$all_cols = array_flip($all_cols);
		} else {
			$all_cols = get_object_vars($this);
			
			unset($all_cols['_tableProperties']);
			unset($all_cols['properties']);
		}
		
		//print_r($all_cols);
		
		$table = $this->getTableName();
		$new_cols = array();
		$ns = '';
		$as = '';
		
		if (!empty($table_namespace)):	
			$ns = $table.'.';
		endif;
				
		foreach ($all_cols as $k => $v) {
			
			if (!empty($as_namespace)):	 
				$as =  ' AS '.$as_namespace.$k;
			endif;
			
			$new_cols[] = $ns.$k.$as;
		}
		
		// add implode as string here
		
		if ($return_as_string == true):
			$new_cols = implode(', ', $new_cols);	
		endif;
		
		//print_r($new_cols);
		return $new_cols; 
		
	}
	
	function getColumnsSql($as_namespace = '', $table_namespace = true) {
	
		return $this->getColumns(true, $as_namespace, $table_namespace);
	}
	
	/**
	 * Sets object attributes
	 *
	 * @param unknown_type $array
	 */
	function setProperties($array) {
		
		$properties = $this->getColumns();
		
		foreach ($properties as $k => $v) {
				
				if (!empty($array[$v])) {
					if (!empty($this->properties)) {
						$this->properties[$v]->setValue($array[$v]);
					} else {
						// old style entities
						$this->$v->setValue($array[$v]);
					}
						
				}
				
			}
		
		return;
	}
	
	function setGuid($string) {
		
		return owa_lib::setStringGuid($string);
		
	}
	
	function set($name, $value) {
		
		if (!empty($this->properties)) {
			$this->properties[$name]->setValue($value);
		} else {
			// old style entities
			$this->$name->setValue($value);
		}
	}
	
	// depricated
	function setValues($values) {
		
		return $this->setProperties($values);
	}
	
	function get($name) {
		if (!empty($this->properties)) {
			return $this->properties[$name]->getValue();
		} else {
			// old style entities
			return $this->$name->getValue();
		}
	}
	
	function getTableOptions() {
		
		if ($this->_tableProperties) {
			if (array_key_exists('table_type', $this->_tableProperties)) {
				return $this->_tableProperties['table_type'];
			}
		}
		
		return array('table_type' => 'disk');		
	
	}
	
	/**
	 * Persist new object
	 *
	 */ 
	function create() {	
		
		$db = owa_coreAPI::dbSingleton();		
		$all_cols = $this->getColumns();
		
		$db->insertInto($this->getTableName());
		
		// Control loop
		foreach ($all_cols as $k => $v){
		
			// drop column is it is marked as auto-incement as DB will take care of that.
			if ($this->$v->auto_increment == true):
				;
			else:
				$db->set($v, $this->get($v));
			endif;
				
		}
	
		// Persist object
		$status = $db->executeQuery();
		
		// Add to Cache
		if ($status == true) {
			if (owa_coreAPI::getSetting('base', 'cache_objects') === true) {
				$this->addToCache();
			}
		}
		return $status;
	}
	
	function addToCache() {
		
		if($this->isCachable()) {
			$cache = owa_coreAPI::cacheSingleton();
			$cache->setCacheDir(OWA_CACHE_DIR);
			$cache->set($this->getTableName(), 'id'.$this->get('id'), $this);
		}
	}
	
	/**
	 * Update all properties of an Existing object
	 *
	 */
	function update($where = '') {	
		
		$db = owa_coreAPI::dbSingleton();	
		$db->updateTable($this->getTableName());
		
		// get column list
		$all_cols = $this->getColumns();
		
		// Control loop
		foreach ($all_cols as $k => $v){
		
			// drop column is it is marked as auto-incement as DB will take care of that.
			
			if ($this->get($v)):
				$db->set($v, $this->get($v));
			endif;
				
		}
		
		if(empty($where)):
			$id = $this->get('id');
			$db->where('id', $id);
			
		else:
			$db->where($where, $this->get($where));
		endif;
		
		// Persist object
		$status = $db->executeQuery();
		// Add to Cache
		if ($status === true) {
			if (owa_coreAPI::getSetting('base', 'cache_objects') === true) {
				$this->addToCache();
			}
		}
		
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
		$db->updateTable($this->getTableName());
		
		foreach ($named_properties as $v) {
			
			if ($this->get($v)){
				$db->set($v, $this->get($v));
			}
		}
		
		if(empty($where)):
			$db->where('id', $this->get('id'));
		else:
			$db->where($where, $this->get($where));
		endif;
		
		// Persist object
		$status = $db->executeQuery();
		// Add to Cache
		if ($status == true) {
			if (owa_coreAPI::getSetting('base', 'cache_objects') === true) {
				$this->addToCache();
			}
		}
		return $status;
	}
	
	
	/**
	 * Delete Object
	 *
	 */
	function delete($value = '', $col = 'id') {	
		
		$db = owa_coreAPI::dbSingleton();	
		$db->deleteFrom($this->getTableName());
		
		if (empty($value)) {
			$value = $this->get('id');
		}
		
		$db->where($col, $value);	

		$status = $db->executeQuery();
	
		// Add to Cache
		if ($status == true){
			if (owa_coreAPI::getSetting('base', 'cache_objects') === true) {
				if ($this->isCachable()) {
					$cache = owa_coreAPI::cacheSingleton();
					$cache->setCacheDir(OWA_CACHE_DIR);
					$cache->remove($this->getTableName(), 'id'.$this->get('id'));
				}
			}
		}
		
		return $status;
		
	}
	
	function load($value, $col = 'id') {
		
		return $this->getByColumn($col, $value);
		
	}
	
	function getByPk($col, $value) {
		
		return $this->getByColumn($col, $value);
		
	}
	
	function getByColumn($col, $value) {
				
		$cache_obj = '';
		
		if (owa_coreAPI::getSetting('base', 'cache_objects') === true) {
			$cache = owa_coreAPI::cacheSingleton();
			$cache->setCacheDir(OWA_CACHE_DIR);
			$cache_obj = $cache->get($this->getTableName(), $col.$value);
		}
			
		if (!empty($cache_obj)) {
		
			$cache_obj_properties = $cache_obj->_getProperties();
			$this->setProperties($cache_obj_properties);
					
		} else {
		
			$db = owa_coreAPI::dbSingleton();
			$db->selectFrom($this->getTableName());
			$db->selectColumn('*');
			$db->where($col, $value);
			$properties = $db->getOneRow();
			
			if (!empty($properties)) {
					
				$this->setProperties($properties);
				
				if (owa_coreAPI::getSetting('base', 'cache_objects') === true) {
		
					$this->addToCache();
				}
			}
		} 
	}

	function getTableName() {
		
		if ($this->_tableProperties) {
			return $this->_tableProperties['name'];
		} else {
			return get_class($this);
		}
		
	}
	
	function setTableName($name, $namespace = 'owa_') {
		
		$this->_tableProperties['name'] = $namespace.$name;
	}	
	
	function setCachable() {
	
		$this->_tableProperties['cacheable'] = true;
	}
	
	function isCachable() {
		
		return $this->_tableProperties['cacheable'];
	}
	
	function setPrimaryKey($col) {
		//backwards compatability
		$this->properties[$col]->setPrimaryKey();
		$this->_tableProperties['primary_key'] = $col;
		
	}
	
	function setForeignKey($col, $table) {
	
		$this->properties[$col]->setForeignKey($table);
		$this->_tableProperties['foreign_keys'][$col] = $table;
	}
	
	/**
	 * Create Table
	 *
	 * Handled by DB abstraction layer because the SQL associated with this is way too DB specific
	 */
	function createTable() {
		
		$db = owa_coreAPI::dbSingleton();
		// Persist table
		$status = $db->createTable($this);
		
		if ($status == true):
			owa_coreAPI::notice(sprintf("%s Table Created.", $this->getTableName()));
			return true;
		else:
			owa_coreAPI::notice(sprintf("%s Table Creation Failed.", $this->getTableName()));
			return false;
		endif;
	
	}
	
	/**
	 * DROP Table
	 *
	 * Drops a table. will throw error is table does not exist
	 */
	function dropTable() {
		
		$db = owa_coreAPI::dbSingleton();
		// Persist table
		$status = $db->dropTable($this->getTableName());
		
		if ($status == true):
			return true;
		else:
			return false;
		endif;
	
	}
	
	function addColumn($column_name) {
		
		$def = $this->getColumnDefinition($column_name);
		// Persist table
		$db = owa_coreAPI::dbSingleton();
		$status = $db->addColumn($this->getTableName(), $column_name, $defs);
		
		if ($status == true):
			return true;
		else:
			return false;
		endif;
		
	}
	
	function dropColumn($column_name) {
		
		$db = owa_coreAPI::dbSingleton();
		$status = $db->dropColumn($this->getTableName(), $column_name);
		
		if ($status == true):
			return true;
		else:
			return false;
		endif;		
		
	}
	
	function modifyColumn($column_name) {
	
		$def = $this->getColumnDefinition($column_name);		
		$db = owa_coreAPI::dbSingleton();
		$status = $db->modifyColumn($this->getTableName(), $column_name, $defs);
		
		if ($status == true):
			return true;
		else:
			return false;
		endif;		
	
	
	}
	
	function renameColumn($old_column_name, $column_name) {
	
		$db = owa_coreAPI::dbSingleton();
		$status = $db->renameColumn($this->getTableName(), $old_column_name, $column_name);
		
		if ($status == true):
			return true;
		else:
			return false;
		endif;		
		
	}
	
	function renameTable($new_table_name) {
		
		$db = owa_coreAPI::dbSingleton();
		$status = $db->renameTable($this->getTableName(), $new_table_name);
		
		if ($status == true):
			return true;
		else:
			return false;
		endif;		
		return;
	}
	
	function getColumnDefinition($column_name) {
	
		if (empty($this->properties)) {
			return $this->$column_name->getDefinition();
		} else {
			return $this->properties[$column_name]->getDefinition();
		}
	}
	
	function setProperty($obj) {
		
		$this->properties[$obj->get('name')] = $obj;
	}
	
	function generateRandomUid($seed = '') {
		
		return crc32($_SERVER['SERVER_ADDR'].$_SERVER['SERVER_NAME'].getmypid().$this->getTableName().microtime().$seed.rand());
	}
	
	/**
	 * Create guid from string
	 *
	 * @param 	string $string
	 * @return 	integer
	 */
	function generateId($string) {
		//require_once(OWA_DIR.'owa_lib.php');
		return owa_lib::setStringGuid($string);
	}

}

?>