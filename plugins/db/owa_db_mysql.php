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
define('OWA_DTD_SERIAL', 'SERIAL');
define('OWA_DTD_PRIMARY_KEY', 'PRIMARY KEY');
define('OWA_DTD_VARCHAR10', 'VARCHAR(10)');
define('OWA_DTD_VARCHAR255', 'VARCHAR(255)');
define('OWA_DTD_VARCHAR', 'VARCHAR(%s)');
define('OWA_DTD_TEXT', 'MEDIUMTEXT');
define('OWA_DTD_BOOLEAN', 'TINYINT(1)');
define('OWA_DTD_TIMESTAMP', 'TIMESTAMP');
define('OWA_DTD_BLOB', 'BLOB');
define('OWA_DTD_INDEX', 'KEY');
define('OWA_DTD_AUTO_INCREMENT', 'AUTO_INCREMENT');
define('OWA_DTD_NOT_NULL', 'NOT NULL');
define('OWA_DTD_UNIQUE', 'PRIMARY KEY(%s)');
define('OWA_SQL_ADD_COLUMN', 'ALTER TABLE %s ADD %s %s');   
define('OWA_SQL_DROP_COLUMN', 'ALTER TABLE %s DROP %s');
define('OWA_SQL_RENAME_COLUMN', 'ALTER TABLE %s CHANGE %s %s %s'); 
define('OWA_SQL_MODIFY_COLUMN', 'ALTER TABLE %s MODIFY %s %s'); 
define('OWA_SQL_RENAME_TABLE', 'ALTER TABLE %s RENAME %s'); 
define('OWA_SQL_CREATE_TABLE', 'CREATE TABLE IF NOT EXISTS %s (%s) %s'); 
define('OWA_SQL_DROP_TABLE', 'DROP TABLE IF EXISTS %s');  
define('OWA_SQL_INSERT_ROW', 'INSERT into %s (%s) VALUES (%s)');
define('OWA_SQL_UPDATE_ROW', 'UPDATE %s SET %s %s');
define('OWA_SQL_DELETE_ROW', "DELETE from %s %s");
define('OWA_SQL_CREATE_INDEX', 'CREATE INDEX %s ON %s (%s)');
define('OWA_SQL_DROP_INDEX', 'DROP INDEX %s ON %s');
define('OWA_SQL_INDEX', 'INDEX (%s)');
define('OWA_SQL_BEGIN_TRANSACTION', 'BEGIN');
define('OWA_SQL_END_TRANSACTION', 'COMMIT');
define('OWA_DTD_TABLE_TYPE', 'ENGINE = %s');
define('OWA_DTD_TABLE_TYPE_DEFAULT', 'INNODB');
define('OWA_DTD_TABLE_TYPE_DISK', 'INNODB');
define('OWA_DTD_TABLE_TYPE_MEMORY', 'MEMORY');
define('OWA_SQL_ALTER_TABLE_TYPE', 'ALTER TABLE %s ENGINE = %s');
define('OWA_SQL_JOIN_LEFT_OUTER', 'LEFT OUTER JOIN');
define('OWA_SQL_JOIN_LEFT_INNER', 'LEFT INNER JOIN');
define('OWA_SQL_JOIN_RIGHT_OUTER', 'RIGHT OUTER JOIN');
define('OWA_SQL_JOIN_RIGHT_INNER', 'RIGHT INNER JOIN');
define('OWA_SQL_JOIN', 'JOIN');
define('OWA_SQL_DESCENDING', 'DESC');
define('OWA_SQL_ASCENDING', 'ASC');
define('OWA_SQL_REGEXP', 'REGEXP');
define('OWA_SQL_NOTREGEXP', 'NOT REGEXP');
define('OWA_SQL_LIKE', 'LIKE');
define('OWA_SQL_ADD_INDEX', 'ALTER TABLE %s ADD INDEX (%s) %s');
define('OWA_SQL_COUNT', 'COUNT(%s)');
define('OWA_SQL_SUM', 'SUM(%s)');
define('OWA_SQL_ROUND', 'ROUND(%s)');
define('OWA_SQL_AVERAGE', 'AVG(%s)');
define('OWA_SQL_DISTINCT', 'DISTINCT %s');
define('OWA_SQL_DIVISION', '(%s / %s)');
define('OWA_DTD_CHARACTER_ENCODING_UTF8', 'utf8');
define('OWA_DTD_TABLE_CHARACTER_ENCODING', 'CHARACTER SET = %s');


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
	
	function connect() {
	
		if ( ! $this->connection ) {
			
			// make a persistent connection if need be.
			if ( $this->getConnectionParam('persistant') ) {
			
				$host = 'p:' . $this->getConnectionParam('host');
			
			} else {
				
				$host = $this->getConnectionParam('host');
			}
			
			// get a connection
			$this->connection = mysqli_connect(
				$host,
				$this->getConnectionParam('user'),
				$this->getConnectionParam('password'),
				$this->getConnectionParam('name')
	    	);
			
			// explicitng set the character set as UTF-8	
			if (function_exists('mysqli_set_charset')) {
			
				mysqli_set_charset($this->connection, 'utf8' );
				
			} else {
				
				$this->query("SET NAMES 'utf8'");
			}
			
			// turn off strict mode. needed on mysql 5.7 and lter when it is turned on by default.
			$this->query( "SET SESSION sql_mode=''" );
			
		}
				
		if ( ! $this->connection ) {
		
			$this->e->alert('Could not connect to database.');
			$this->connection_status = false;
			return false;
			
		} else {
			
			$this->connection_status = true;
			return true;
		}
	}
	
	
	/**
	 * Database Query
	 *
	 * @param 	string $sql
	 * @access 	public
	 * 
	 */
	function query( $sql ) {
  
  		if ( $this->connection_status == false) {
  			
  			owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
  			
  			$this->connect();
  			
  			owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
  		}
  
  		owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
		
		$this->e->debug(sprintf('Query: %s', $sql));
		
		$this->result = array();
		
		$this->new_result = '';	
		
		if ( ! empty( $this->new_result ) ) {
		
			mysqli_free_result($this->new_result);
		}
		
		owa_coreAPI::profile($this, __FUNCTION__, __LINE__, $sql);
		
		$result = @mysqli_query( $this->connection, $sql );
		
		owa_coreAPI::profile($this, __FUNCTION__, __LINE__);			
		// Log Errors
		
		if ( mysqli_errno( $this->connection ) ) {
			
			$this->e->debug(
				sprintf(
					'A MySQL error ocured. Error: (%s) %s. Query: %s',
					mysqli_errno( $this->connection ), 
					htmlspecialchars( mysqli_error( $this->connection ) ),
					$sql
				)
			);
		}
		
		owa_coreAPI::profile($this, __FUNCTION__, __LINE__);
		
		$this->new_result = $result;
		
		return $this->new_result;
	}
	
	function close() {
		
		@mysqli_close( $this->connection );
	}
	
	/**
	 * Fetch result set array
	 *
	 * @param 	string $sql
	 * @return 	array
	 * @access  public
	 */
	function get_results( $sql ) {
	
		if ( $sql ) {
		
			$this->query($sql);
		}
	
		//$this->result = array();
		while ( $row = mysqli_fetch_assoc( $this->new_result ) ) {
			
			array_push($this->result, $row);
		
		}
		
		if ( $this->result ) {
						
			return $this->result;
			
		} else {
		
			return null;
		}
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
		$row = @mysqli_fetch_assoc($this->new_result);
		
		return $row;
	}
	
	/**
	 * Prepares and escapes string
	 *
	 * @param string $string
	 * @return string
	 */
	function prepare( $string ) {
		
		if ($this->connection_status == false) {
  			$this->connect();
  		}
		
		return mysqli_real_escape_string( $this->connection, $string ); 
		
	}
	
	function getAffectedRows() {
		
		return mysqli_affected_rows();
	}
}

?>