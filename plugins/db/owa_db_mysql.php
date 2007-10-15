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
 * MySQL Connection class
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
		
		//$connectionString = sprintf('%s', OWA_DB_HOST);	
		
		/*$this->connection = mysql_connect(
			OWA_DB_HOST,
			OWA_DB_USER,
			OWA_DB_PASSWORD,
			true
    	);
		
		$this->database_selection = mysql_select_db(OWA_DB_NAME, $this->connection);
		
		if (!$this->connection || !$this->database_selection):
			$this->e->alert('Could not connect to database. ');
			$this->connection_status = false;
		else:
			$this->connection_status = true;
		endif;
	*/
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
				$this->e->alert('Could not connect to database. ');
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
		
		// hack for when calling applications catch all mysql errors and you nee to flush the error
		// this only is an issue with respect to inserts that fail.
		if ($result == false):
			;//mysql_ping($this->connection);
		endif;
		
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
					"INSERT into %s (%s) VALUES (%s)",
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
		
		return $this->query(sprintf("UPDATE %s SET %s WHERE %s", $table, $set, $where));
		
	}
	
	function delete($id, $col, $table) {
		
		return $this->query(sprintf("DELETE from %s WHERE %s = '%s'", $table, $col, $id));
		
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