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
		
		$connectionString = sprintf(
			'%s',
			$this->config['db_host']
		);	
		
		$this->connection = @mysql_connect(
			$connectionString,
			$this->config['db_user'],
			$this->config['db_password'],
			true
    	);
		
		$this->database_selection = @mysql_select_db($this->config['db_name'], $this->connection);
		
		if (!$this->connection || !$this->database_selection):
			$this->e->alert('Could not connect to database. ');
			$this->connection_status = false;
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
  
		$this->e->debug(sprintf('Query: %s', $sql));
		
		@mysql_free_result($this->new_result);
		$this->result = '';
		$this->new_result = '';
		$result = @mysql_unbuffered_query($sql, $this->connection);
					
		// Log Errors
		if (mysql_errno()):
			$this->e->debug(sprintf('A MySQL error occured. Error: (%s) %s. Query: %s',
			mysql_errno(),
			htmlspecialchars(mysql_error()),
			$sql));
		endif;			
		
		$this->new_result = $result;
		
		// hack for when calling applications catch all mysql errors and you nee to flush the error
		// this only is an issue with respect to inserts that fail.
		if ($result == false):
			//mysql_ping($this->connection);
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
		$row = mysql_fetch_assoc($this->new_result);
		
		return $row;
	}
	
	/**
	 * Prepares and escapes string
	 *
	 * @param string $string
	 * @return string
	 */
	function prepare($string) {
		
		$string = mysql_real_escape_string($string, $this->connection); 
		return $string;
	}

}

?>
