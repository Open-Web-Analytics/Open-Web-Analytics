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

require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'owa_base.php');

/**
 * Database Connection Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_db extends owa_base {
	
	/**
	 * Connection string
	 *
	 * @var string
	 */
	var $connection;
	
	/**
	 * Number of queries
	 *
	 * @var integer
	 */
	var $num_queries;
	
	/**
	 * Raw result object
	 *
	 * @var object
	 */
	var $new_result;
	
	/**
	 * Rows
	 *
	 * @var array
	 */
	var $result;
	
	/**
	 * Caller Params
	 *
	 * @var array
	 */
	var $params = array();
	
	/**
	 * Status of selecting a databse
	 *
	 * @var boolean
	 */
	var $database_selection;
	
	/**
	 * Status of connection
	 *
	 * @var boolean
	 */
	var $connection_status;
	
	/**
	 * Number of rows in result set
	 *
	 * @var integer
	 */
	var $num_rows;
	
	/**
	 * Number of rows affected by insert/update/delete statements
	 *
	 * @var integer
	 */
	var $rows_affected;
	
	/**
	 * Microtime Start of Query
	 *
	 * @var unknown_type
	 */
	var $_start_time;
	
	/**
	 * Total Elapsed time of query
	 *
	 * @var unknown_type
	 */
	var $_total_time;

	/**
	 * Storage Array for components of sql queries
	 *
	 * @var array
	 */
	var $_sqlParams = array();
	
	/**
	 * Sql Statement
	 *
	 * @var string
	 */
	var $_sql_statement;
	
	/**
	 * Last Sql Statement
	 *
	 * @var string
	 */
	var $_last_sql_statement;
	
	/**
	 * Constructor
	 *
	 * @return 	owa_db
	 * @access 	public
	 */
	function owa_db() {
	
		$this->owa_base();
		
		return;
	}

	/**
	 * Connection object factory
	 *
	 * @return 	object
	 * @access 	public
	 * @static 
	 */
	function &get_instance($params = array()) {
		
		static $db;
		
		if (!isset($db)):
			//print 'hello from db';
			$c = &owa_coreAPI::configSingleton();
			$config = $c->fetch('base');
			
			$e = &owa_error::get_instance();
			
			if (empty($config['db_class'])):
				$class = $config['db_type'];
			else:
				$class = $config['db_class'];
			endif;

			$connection_class = "owa_db_" . $class;
			$connection_class_path = $config['db_class_dir'] . $connection_class . ".php";

	 		if (!@include($connection_class_path)):
	 		
	 			$e->emerg(sprintf('Cannot locate proper db class at %s. Exiting.',
	 							$connection_class_path));
	 			return;
			else:  	
				$db = new $connection_class;
				
				//$this->e->debug(sprintf('Using db class at %s.',	$connection_class_path));
			endif;	
			
		endif;
		
		return $db;
	}
	
	/**
	 * Prepare string
	 *
	 * @param string $string
	 * @return string
	 */
	function prepare_string($string) {
	
		$chars = array("\t", "\n");
		return str_replace($chars, " ", $string);
	}

	/**
	 * Starts the query microtimer
	 *
	 */
	function _timerStart() {
		
	  $mtime = microtime(); 
      //$mtime = explode(' ', $mtime); 
      //$this->_start_time = $mtime[1].substr(round($mtime[0], 4), 1);
	$this->_start_time = microtime();	
	return;
	}
	
	/**
	 * Ends the query microtimer and populates $this->_total_time
	 *
	 */
	function _timerEnd() {
		
		$mtime = microtime(); 
    	//$mtime = explode(" ", $mtime); 
    	//$endtime = $mtime[1].substr(round($mtime[0], 4), 1); 
		$endtime = microtime();
		//$this->_total_time = bcsub($endtime, $this->_start_time, 4); 
		$this->_total_time = number_format(((substr($endtime,0,9)) + (substr($endtime,-10)) - (substr($this->_start_time,0,9)) - (substr($this->_start_time,-10))),6);
		
		return;
		
	}
	
	function selectColumn($name, $as = '') {
		
		$this->_sqlParams['select_values'][] = array('name' => $name, 'as' => $as);
		
		return;
	}
	
	function where($name, $value, $operator = '') {
		
		if (empty($operator)):
			$operator = '=';
		endif;
		
		if (!empty($value)):
		
			// hack for intentional empty value
			if($value == ' '):
				$value = '';
			endif;
			
			$this->_sqlParams['where'][$name] = array('name' => $name, 'value' => $value, 'operator' => $operator);
		endif;
		
		return;
	}
	
	function multiWhere($where_array = array()) {
	
		if (!empty($where_array)):
		
			foreach ($where_array as $k => $v) {
				if (!empty($v)):
				
					if (empty($v['operator'])):
						$v['operator'] = '=';
					endif;
					
					$this->_sqlParams['where'][$k] = array('name' => $k, 'value' => $v['value'], 'operator' => $v['operator']);
				endif;
			}
			
		endif;
	}
	
	function groupBy($col) {
		
		$this->_sqlParams['groupby'][] = $col;
		return;
	}
	
	function orderBy($col) {
		
		$this->_sqlParams['orderby'][] = $col;
		return;
	}
	
	function order($flag) {
		
		$this->_sqlParams['order'] = $flag;
		return;
	}
	
	function limit($value) {
		
		$this->_sqlParams['limit'] = $value;
		return;
	}
	
	function offset($value) {
		
		$this->_sqlParams['offset'] = $value;
		return;
	}
	
	function set($name, $value) {
		
		$this->_sqlParams['set_values'][] = array('name' => $name, 'value' => $value);
		return;
	}
		
	function executeQuery() {
		
		switch($this->_sqlParams['query_type']) {
		
			case 'insert':
				
				return $this->_insertQuery();
				
			case 'select':
			
				return $this->_selectQuery();
				
			case 'update':
				
				return $this->_updateQuery();
				
			case 'delete':
			
				return $this->_deleteQuery();
			
			default:
				
				return $this->_query();
		}
	}
	
	function getAllRows() {
		
		 return $this->_selectQuery();
	}
	
	function getOneRow() {
		
		 $ret = $this->_selectQuery();
		 return $ret[0];
	}
	
	function _setSql($sql) {
		$this->_sql_statement = $sql;
	}
	
	function selectFrom($name, $as = '') {
		
		//if (empty($as)):
		//	$as = $this->removeNs($name);
		//endif;
		
		$this->_sqlParams['query_type'] = 'select';
		$this->_sqlParams['from'][$name] = array('name' => $name, 'as' => $as);
		return;
	}
	
	function insertInto($table) {
		
		$this->_sqlParams['query_type'] = 'insert';
		$this->_sqlParams['table'] = $table;
		return;
	}
	
	function deleteFrom($table) {
		
		$this->_sqlParams['query_type'] = 'delete';
		$this->_sqlParams['table'] = $table;
		return;
	}
	
	function updateTable($table) {
		
		$this->_sqlParams['query_type'] = 'update';
		$this->_sqlParams['table'] = $table;
		return;
	}
	
	function _insertQuery() {
	
		$params = $this->_fetchSqlParams('set_values');
		
		$count = count($params);
			
		$i = 0;
			
		$sql_cols = '';
		$sql_values = '';
						
		foreach ($params as $k => $v) {
				
			$sql_cols .= $v['name'];
			$sql_values .= "'".$this->prepare($v['value'])."'";
					
			$i++;
					
			// Add commas
			if ($i < $count):
					
				$sql_cols .= ", ";
				$sql_values .= ", ";
						
			endif;	
		}
		
		$this->_setSql(sprintf(OWA_SQL_INSERT_ROW, $this->_sqlParams['table'], $sql_cols, $sql_values));
		
		return $this->_query();	
	
	}
	
	function _selectQuery() {
	
		$cols = '';
		$i = 0;
		$params = $this->_fetchSqlParams('select_values');
		$count = count($params);
		
		foreach ($params as $k => $v) {
			
			$cols .= $v['name'];
			
			// Add as
			if (!empty($v['as'])):
			
				$cols .= ' as '.$v['as'];
				
			endif;
			
			// Add commas
			if ($i < $count - 1):
				
				$cols .= ', ';
					
			endif;	
			
			$i++;
			
		}
		
		$this->_setSql(sprintf("SELECT %s FROM %s %s %s %s %s", 
										$cols, 
										$this->_makeFromClause(), 
										$this->_makeWhereClause(),
										$this->_makeGroupByClause(),
										$this->_makeOrderByClause(),
										$this->_makeLimitClause()
										));
		return $this->_query();
	
	}

		
	function _updateQuery() {
	
		$params = $this->_fetchSqlParams('set_values');
		
		$count = count($params);
		
		$i = 0;
		
		$sql_cols = '';
		$sql_values = '';
		$set = '';
					
		foreach ($params as $k => $v) {
			
			//$sql_cols = $sql_cols.$key;
			//$sql_values = $sql_values."'".$this->prepare($value)."'";
				
			// Add commas
			if ($i != 0):
				
				$set .= ', ';
					
			endif;	
			
			$set .= $v['name'] .' = \'' . $this->prepare($v['value']) . '\'';
			
			$i++;
		}
		
		$this->_setSql(sprintf(OWA_SQL_UPDATE_ROW, $this->_sqlParams['table'], $set, $this->_makeWhereClause()));
		
		return $this->_query();
		

	
	}
	
	function _deleteQuery() {
	
		$this->_setSql(sprintf(OWA_SQL_DELETE_ROW, $this->_sqlParams['table'], $this->_makeWhereClause()));
		
		return $this->_query();
	}
	
	function rawQuery($sql) {
	
		$this->_setSql($sql);
		
		return $this->_query();
	}
	
	function _fetchSqlParams($sql_params_name) {
	
		if (array_key_exists($sql_params_name, $this->_sqlParams)):
			if (!empty($this->_sqlParams[$sql_params_name])):
				return $this->_sqlParams[$sql_params_name];
			else:
				return false;
			endif;
		else:
			return false;
		endif;
	}
	
	function _makeWhereClause() {
	
		$params = $this->_fetchSqlParams('where');
	
		if (!empty($params)):
		
			$count = count($params);
			
			$i = 0;
			
			$where = 'WHERE ';
			
			foreach ($params as $k => $v) {
				//print_r($v);	
				switch (strtolower($v['operator'])) {
					case 'between':
					
						$where .= sprintf("%s BETWEEN '%s' AND '%s'", $v['name'], $v['value']['start'], $v['value']['end']);
						break;
					default:
						$where .= sprintf("%s %s '%s'",$v['name'], $v['operator'], $v['value']);		
						break;
				}
					
						
					
				if ($i < $count - 1):
						
					$where .= " AND ";
						
				endif;
	
				$i++;	
				
					
			}
			
			return $where;
				
		else:
			
			return;
					
		endif;

	}
	
	function join($type, $table, $as, $foreign_key, $primary_key = '') {
		
		if (empty($primary_key)):
			$primary_key = $table.'.id';
		endif;
		
		
		
		$this->_sqlParams['joins'][] = array('type' => $type, 
											 'table' => $table, 
											 'as' => $as, 
											 'foreign_key' => $foreign_key, 
											 'primary_key' => $primary_key);
		
	}
	
	function _makeJoinClause() {
	
		$params = $this->_fetchSqlParams('joins');
	
		if (!empty($params)):
		
			$join_clause = '';
			
			foreach ($params as $k => $v) {
			
				if (!empty($v['as'])):
					$join_clause .= sprintf(" %s %s AS %s ON %s = %s", $v['type'], 
																 $v['table'], 
																 $v['as'], 
																 $v['foreign_key'], 
																 $v['primary_key']);
				else:
					$join_clause .= sprintf(" %s %s ON %s = %s", $v['type'], 
																 $v['table'], 																														 $v['foreign_key'], 
																 $v['primary_key']);
				endif;
				
					
			
			}
			
			return $join_clause;
		
		else:
			return;
		endif;
		
	}
	
	function _makeFromClause() {
	
		$from = '';
		$i = 0;
		$params = $this->_fetchSqlParams('from');
		
		if(!empty($params)):
		
			$count = count($params);
			
			foreach ($params as $k => $v) {
				
				$from .= $v['name'];
				
				// Add as
				if (!empty($v['as'])):
				
					$from .= ' as '.$v['as'];
					
				endif;
				
				// Add commas
				if ($i < $count - 1):
					
					$from .= ', ';
						
				endif;	
				
				$i++;
				
			}
			
			$from .= $this->_makeJoinClause();
			
			return $from;
		else:
			$this->e->debug("No SQL FROM params set.");
			return false;
		endif;
	
	}
	
	function _makeGroupByClause() {
		
		$params = $this->_fetchSqlParams('groupby');
		
		if (!empty($params)):
			
			return sprintf("GROUP BY %s", $this->_makeDelimitedValueList($params));
			
		else:
			return;	
		endif;

		
	}
	
	function _makeOrderByClause() {
		
		$params = $this->_fetchSqlParams('orderby');
		
		if (!empty($params)):
		
			$order = $this->_fetchSqlParams('order');
			
			if(empty($order)):
				$order = 'DESC';
			endif;
			
			return sprintf("ORDER BY %s %s", $this->_makeDelimitedValueList($params), $order);
			
		else:
			return;	
		endif;
		
				
	}
	
	function _makeLimitClause() {
	
		$param = $this->_fetchSqlParams('limit');
		
		if(!empty($param)):
			$limit = sprintf("LIMIT %d", $param);
			
			$offset = $this->_makeOffsetClause();
			
			$ret = $limit . ' ' . $offset;
					
			return $ret;
		else:
			return;
		endif;
		
	}
	
	function _makeOffsetClause() {
		
		$param = $this->_fetchSqlParams('offset');
		
		if(!empty($param)):
			return sprintf("OFFSET %d", $param);
		else:
			return;
		endif;
		
	}
	
	function _makeDelimitedValueList($values, $delimiter = ', ') {
		
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
	
	function _query() {
		
		switch($this->_sqlParams['query_type']) {
		
			case 'insert':
				
				$ret = $this->query($this->_sql_statement);
				break;
			case 'select':
			
				$results = $this->get_results($this->_sql_statement);
				$ret = $this->_formatResults($results);
				//$count = count($results);
				break;
				
			case 'update':
				
				$ret = $this->query($this->_sql_statement);
				break;
			case 'delete':
			
				$ret = $this->query($this->_sql_statement);
				break;
		}
		
		$this->_last_sql_statement = $this->_sql_statement;
		$this->_sql_statement = '';
		$this->_sqlParams = array();
		return $ret;
		
	}

	function removeNs($string, $ns = '') {
		
		if (empty($ns)):
			$ns = $this->config['ns'];
		endif;
		
		$ns_len = strlen($ns);
		return substr($string, $ns_len);
		
	}
	
	function setFormat($value) {
		
		$this->_sqlParams['result_format'] = $value;
		return;
	}
	
	function _formatResults($results) {
		
		switch ($this->_sqlParams['result_format']) {
				
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
	 * Changes the definition of a column
	 *
	 */
	function modifyColumn($table_name, $column_name, $column_definition) {
	
		return $this->query(sprintf(OWA_SQL_MODIFY_COLUMN, $table_name. $column_name, $column_definition));

	}

}

?>