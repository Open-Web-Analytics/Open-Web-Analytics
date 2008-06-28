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


define('OWA_DTD_BIGINT', 'BIGINT'); 
define('OWA_DTD_INT', 'INT');
define('OWA_DTD_TINYINT', 'TINYINT(1)');
define('OWA_DTD_TINYINT2', 'TINYINT(2)');
define('OWA_DTD_TINYINT4', 'TINYINT(4)');
define('OWA_DTD_BOOLEAN', 'BOOLEAN');
define('OWA_DTD_SERIAL', 'SERIAL');
define('OWA_DTD_PRIMARY_KEY', 'PRIMARY KEY');
define('OWA_DTD_VARCHAR10', 'VARCHAR(10)');
define('OWA_DTD_VARCHAR255', 'VARCHAR(255)');
define('OWA_DTD_VARCHAR', 'VARCHAR(%s)');
define('OWA_DTD_TEXT', 'TEXT'); 
define('OWA_DTD_INDEX', 'KEY');
define('OWA_DTD_AUTO_INCREMENT', 'AUTO_INCREMENT');
define('OWA_DTD_NOT_NULL', 'NOT NULL');
define('OWA_DTD_UNIQUE', 'UNIQUE'); 
define('OWA_DTD_UNIQUE', 'PRIMARY KEY(%s)');
define('OWA_SQL_ADD_COLUMN', 'ALTER TABLE %s ADD %s %s');   
define('OWA_SQL_DROP_COLUMN', 'ALTER TABLE %s DROP %s'); 
define('OWA_SQL_MODIFY_COLUMN', 'ALTER TABLE %s MODIFY %s %s'); 
define('OWA_SQL_RENAME_TABLE', 'ALTER TABLE %s RENAME %s'); 
define('OWA_SQL_CREATE_TABLE', 'CREATE TABLE %s (%s) %s'); 
define('OWA_SQL_DROP_TABLE', 'DROP TABLE IF EXISTS %s');  
define('OWA_SQL_INSERT_ROW', 'INSERT into %s (%s) VALUES (%s)');
define('OWA_SQL_UPDATE_ROW', 'UPDATE %s SET %s WHERE %s');
define('OWA_SQL_DELETE_ROW', "DELETE from %s WHERE %s = '%s'");
define('OWA_SQL_CREATE_INDEX', 'CREATE INDEX %s ON %s (%s)');
define('OWA_SQL_DROP_INDEX', 'DROP INDEX %s ON %s');
define('OWA_SQL_INDEX', 'INDEX (%s)');
define('OWA_SQL_BEGIN_TRANSACTION', 'BEGIN');
define('OWA_SQL_END_TRANSACTION', 'COMMIT');
define('OWA_DTD_TABLE_TYPE', 'ENGINE = %s');
define('OWA_DTD_DEFAULT_TABLE_TYPE', 'INNODB');
define('OWA_DTD_TABLE_TYPE_DISK', 'INNODB');
define('OWA_DTD_TABLE_TYPE_MEMORY', 'MEMORY');
define('OWA_SQL_ALTER_TABLE_TYPE', 'ALTER TABLE %s ENGINE = %s');



/**
 * MySQL Data Access Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_db_mysql extends owa_db {

	/**
	 * Constructor
	 *
	 * @return owa_db_mysql
	 * @access public
	 */
	function owa_db_mysql() {
	
		$this->owa_db();
		
		return;
	}
	
	function connect() {
	
		$this->connection = mysql_connect(
				OWA_DB_HOST,
				OWA_DB_USER,
				OWA_DB_PASSWORD,
				true
    	);
		
		$this->database_selection = mysql_select_db(OWA_DB_NAME, $this->connection);
			
		if (!$this->connection || !$this->database_selection):
				$this->e->alert('Could not connect to database.');
				$this->connection_status = false;
				return false;
		else:
				$this->connection_status = true;
		endif;
		
		return;
	
	}
	
	
	/**
	 * Database Query
	 *
	 * @param 	string $sql
	 * @access 	public
	 * 
	 */
	function query($sql) {
  
  		if ($this->connection_status == false):
  			$this->connect();
  		endif;
  
  
		$this->e->debug(sprintf('Query: %s', $sql));
		
		$this->result = '';
		$this->new_result = '';	
		
		if (!empty($this->new_result)):
			mysql_free_result($this->new_result);
		endif;
		
		$result = @mysql_unbuffered_query($sql, $this->connection);
					
		// Log Errors
		if (mysql_errno($this->connection)):
			$this->e->debug(sprintf('A MySQL error occured. Error: (%s) %s. Query: %s',
			mysql_errno($this->connection),
			htmlspecialchars(mysql_error($this->connection)),
			$sql));
		endif;			
		
		$this->new_result = $result;
		
		return $this->new_result;
		
	}
	
	function close() {
		
		@mysql_close($this->connection);
		return;
		
	}
	
	/**
	 * Fetch result set array
	 *
	 * @param 	string $sql
	 * @return 	array
	 * @access  public
	 */
	function get_results($sql) {
	
		if ($sql):
			$this->query($sql);
		endif;
	
		$num_rows = 0;
		
		while ( $row = @mysql_fetch_object($this->new_result) ) {
			$this->result[$num_rows] = $row;
			$num_rows++;
		}
		
		if ($this->result):
			$i = 0;
			foreach($this->result as $row ) {
				
				// Hook for caching goes here
				
				$new_array[$i] = (array) $row;
				$i++;
			}
			
			
			return $new_array;
			
		else:
			return null;
		endif;
	}
	
	/**
	 * Fetch Single Row
	 *
	 * @param string $sql
	 * @return array
	 */
	function get_row($sql) {
		
		$this->query($sql);
		
		//print_r($this->result);
		$row = @mysql_fetch_assoc($this->new_result);
		
		return $row;
	}
	
	/**
	 * Prepares and escapes string
	 *
	 * @param string $string
	 * @return string
	 */
	function prepare($string) {
		
		if ($this->connection_status == false):
  			$this->connect();
  		endif;
		
		return mysql_real_escape_string($string, $this->connection); 
		
	}
	
	/**
	 * Save Request to database
	 *
	 */
	function save($properties, $table) {	
		
		$count = count($properties);
		
		$i = 0;
		
		$sql_cols = '';
		$sql_values = '';
					
			foreach ($properties as $key => $value) {
			
				$sql_cols = $sql_cols.$key;
				$sql_values = $sql_values."'".$this->prepare($value)."'";
				
				$i++;
				
				// Add commas
				if ($i < $count):
				
					$sql_cols = $sql_cols.", ";
					$sql_values = $sql_values.", ";
					
				endif;	
			}
						
		return $this->query(sprintf(
					OWA_SQL_INSERT_ROW,
					$table,
					$sql_cols,
					$sql_values)
				);	
		
	}
	
	function update($properties, $constraints, $table) {
		
		$count = count($properties);
		
		$i = 0;
		
		$sql_cols = '';
		$sql_values = '';
		$set = '';
					
		foreach ($properties as $key => $value) {
			
			//$sql_cols = $sql_cols.$key;
			//$sql_values = $sql_values."'".$this->prepare($value)."'";
				
			// Add commas
			if ($i != 0):
				
				$set .= ', ';
					
			endif;	
			
			$set .= $key .' = \'' . $this->prepare($value) . '\'';
			
			$i++;
		}
		
		$where = owa_lib::addConstraints($constraints);
		
		return $this->query(sprintf(OWA_SQL_UPDATE_ROW, $table, $set, $where));
		
	}
	
	/**
	 * Deletes Row from a table
	 *
	 */
	function delete($id, $col, $table) {
		
		return $this->query(sprintf(OWA_SQL_DELETE_ROW, $table, $col, $id));
		
	}
	
	/**
	 * Creates a new table
	 *
	 */
	function createTable($entity) {
	
		//create column defs
		
		$all_cols = $entity->getColumns();
		
		$columns = '';
	
		$table_defs = '';
		
		$i = 0;
		$count = count($all_cols);
		
		// Control loop
		foreach ($all_cols as $k => $v){
			
			// get column definition 
			$columns .= $v.' '.$entity->$v->getDefinition();
						
			// Add commas to column statement
			if ($i < $count - 1):
				
				$columns .= ', ';
					
			endif;	
			
			$i++;
				
		}
		
		// make table options
		$table_options = '';
		$options = $entity->getTableOptions();
		
		// table type
		switch ($options['table_type']) {
		
			case "disk":
				$table_type = OWA_DTD_TABLE_TYPE_DISK;
				break;
			case "memory":
				$table_type = OWA_DTD_TABLE_TYPE_MEMORY;
				break;
			default:
				$table_type = OWA_DTD_TABLE_TYPE_DEFAULT;
	
		}
		
		$table_options .= sprintf(OWA_DTD_TABLE_TYPE, $table_type);
			
		return $this->query(sprintf(OWA_SQL_CREATE_TABLE, get_class($entity), $columns, $table_options));
		
	}
	
	/**
	 * Drops a table
	 *
	 */
	function dropTable($table_name) {
	
		return $this->query(sprintf(OWA_SQL_DROP_TABLE, $table_name));
	
	}
	
	/**
	 * Change table type
	 *
	 */
	function alterTableType($table_name, $engine) {
	
		return $this->query(sprintf(OWA_SQL_ALTER_TABLE_TYPE, $table_name, $engine));
	
	}
	
	
	/**
	 * Rename a table
	 *
	 */
	function renameTable($table_name, $new_table_name) {
	
		return $this->query(sprintf(OWA_SQL_RENAME_TABLE, $table_name, $new_table_name));
	
	}

	
	/**
	 * Adds new column to table
	 *
	 */
	function addColumn($table_name, $column_name, $column_definition) {
	
		return $this->query(sprintf(OWA_SQL_ADD_COLUMN, $table_name. $column_name, $column_definition));

	}
	
	/**
	 * Drops a column from a table
	 *
	 */
	function dropColumn($table_name, $column_name) {
	
		return $this->query(sprintf(OWA_SQL_DROP_COLUMN, $table_name. $column_name));

	}
	
	/**
	 * Begins a SQL transaction statement
	 *
	 */
	function beginTransaction() {
	
		return $this->query(OWA_SQL_BEGIN_TRANSACTION);
	}
	
	/**
	 * Ends a SQL transaction statement
	 *
	 */
	function endTransaction() {
	
		return $this->query(OWA_SQL_END_TRANSACTION);
	}
	
	/**
	 * Changes the definition of a column
	 *
	 */
	function modifyColumn($table_name, $column_name, $column_definition) {
	
		return $this->query(sprintf(OWA_SQL_MODIFY_COLUMN, $table_name. $column_name, $column_definition));

	}

	
	function select($values, $constraints, $table) {
		
		$cols = '';
		$i = 0;
		$count = count($values);
		
		foreach ($values as $k => $v) {
			
			$cols .= $k;
			
			// Add commas
			if ($i < $count - 1):
				
				$cols .= ', ';
					
			endif;	
			
			$i++;
			
		}
		
		$where = owa_lib::addConstraints($constraints);
		
		return $this->get_row(sprintf("SELECT %s FROM %s WHERE %s", $cols, $table, $where));
		
	}
	
	/**
	 * Fetches primary and related objects from DB
	 *
	 * @param array $params caller params
	 * @return array
	 */
	function getObjs($params) {
		
		// Adds caller params to class var
		$this->params = $params;
		
		// construct COLUMNS
		
		$cols = $this->makeColumnList($this->params['primary_obj']);
		
		// add related objects
		if(!empty($this->params['related_objs'])):
		
			foreach ($this->params['related_objs'] as $k => $v) {
			
				$cols .= ', '.$this->makeColumnList($v->entity);
			
			}
		
		endif;
		
		
		return $this->buildSelectStm($cols);
	}
	
	function selectQuery($params) {
		
		// Adds caller params to class var
		$this->params = $params;
		
		return $this->buildSelectStm($this->params['select']);
		
		
	}
	
	function buildSelectStm($cols) {
		
		$orderby_stm = '';
		$limit_stm = '';
		$offset_stm = '';
		// construct FROM
		
		$from = $this->makeFromStm();
		
		// construct WHERE
		if(!empty($this->params['constraints'])):
			$where = 'WHERE '.owa_lib::addConstraints($this->params['constraints']);
		endif;
		
		// construct GROUP BY
		
		if(!empty($this->params['groupby'])):
	
			$groupby_stm = $this->makeGroupByStm();
		
		endif;
		
		// construct ORDER
		
		if(!empty($this->params['orderby'])):
		
			$orderby_stm = $this->makeOrderByStm();
			
		endif;
		
		// construct LIMIT
		
		if(!empty($this->params['limit'])):
		
			$limit_stm = $this->makeLimitStm();
			
		endif;
		
		// construct OFFSET
		
		if(!empty($this->params['offset'])):
		
			$offset_stm = $this->makeOffsetStm();
			
		endif;
		
		// Issue query
	
		$ret = $this->get_results(sprintf("SELECT %s FROM %s %s %s %s %s %s", $cols, $from, $where, $groupby_stm, $orderby_stm, $limit_stm, $offset_stm));
		
		// format results
		if(!empty($ret)):
		
			$ret = $this->formatResults($ret);
			
		endif;
		
		// clear out params.
		$this->params = '';
		
		return $ret;
		
		
	}
	
	function formatResults($results) {
		
		switch ($this->params['result_format']) {
				
				case "single_array":
					return $results[0];
					break;
				case "single_row":
					return $results[0];
					break;	
				case "inverted_array":
					return owa_lib::deconstruct_assoc($results);
					break;
				default:
					return $results;
					break;
		}	
	
	}
	
	function makeFromStm() {
		
		// construct FROM
		
		$primary_obj_ns = $this->removeNs(get_class($this->params['primary_obj']));
		
		$from = get_class($this->params['primary_obj']) . ' as ' . $primary_obj_ns;
		
		// add related objects
		if(!empty($this->params['related_objs'])):
		
			foreach ($this->params['related_objs'] as $k => $v) {
				$joinTableNs = $this->removeNs(get_class($v->entity));
				
				$from .= ' LEFT OUTER JOIN ' . get_class($v->entity) . ' as ' . $joinTableNs . ' ON ' . $primary_obj_ns . '.' . $k . ' = ' . $joinTableNs . '.id';
			}
		
		endif;
		
		return $from;
		
	}
	
	function makeGroupByStm() {
		
		return 'GROUP BY ' . $this->makeDelimitedList($this->params['groupby']);
		
	}
	
	function makeOrderByStm() {
		
		if (empty($this->params['order'])):
			$this->params['order'] = 'DESC';	
		endif;
		
		return 'ORDER BY ' . $this->makeDelimitedList($this->params['orderby']). ' '. $this->params['order'];
		
	}
	
	function makeLimitStm() {
		
		return 'LIMIT ' . $this->params['limit'];
		
	}
	
	function makeOffsetStm() {
		
		return 'OFFSET ' . $this->params['offset'];
		
	}
	
	function makeColumnList($obj) {
		
		$values = $obj->getColumns();
		
		$ns = $this->removeNs(get_class($obj));
		$cols = '';
		$i = 0;
		$count = count($values);
		
		foreach ($values as $k => $v) {
			
			if (empty($this->params['related_objs'])):
				$cols .= $v;
			else:
				$cols .= $ns.'.'.$v.' as '.$ns.'_'.$v;
			endif;
			
			// Add commas
			if ($i < $count - 1):
				
				$cols .= ', ';
					
			endif;	
			
			$i++;
			
		}
		
		return $cols;
		
	}
	
	function removeNs($string) {
		
		$ns_len = strlen($this->config['ns']);
		return substr($string, $ns_len);
		
	}
	
	function makeDelimitedList($values, $delimiter = ', ') {
		
		$items = '';
		$i = 0;
		$count = count($values);
		
		if (is_array($values)):
		
			foreach ($values as $k) {
				
				$items .= $k;
				
				// Add commas
				if ($i < $count - 1):
					
					$items .= $delimiter;
						
				endif;	
				
				$i++;
				
			}
			
		else:
		
			$items = $values;
		
		endif;
		
		return $items;
		
	}

}

?>