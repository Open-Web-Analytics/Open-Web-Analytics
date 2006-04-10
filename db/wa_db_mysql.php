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
 * @category    wa
 * @package     wa
 * @version		$Revision$	      
 * @since		wa 1.0.0
 */
class wa_db_mysql extends wa_db {

	/**
	 * Constructor
	 *
	 * @return wa_db_mysql
	 * @access public
	 */
	function wa_db_mysql() {
	
		$this->wa_db();
		
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
		
		if (!$this->connection || !@mysql_select_db($this->config['db_name'], $this->connection)):
			return print 'Could not connect to database.';
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
  
		if ($this->config['debug_level'] == 1):
			$this->debug_sql($sql);
		endif;
	
		@mysql_free_result($this->new_result);
		$this->result = '';
		$this->new_result = '';
		$this->new_result = @mysql_unbuffered_query($sql, $this->connection);
					
		/*$num_rows = 0;
		
		while ( $row = @mysql_fetch_object($this->new_result) ) {
			$this->result[$num_rows] = $row;
			$num_rows++;
		}*/
				
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
	
		if ($this->config['debug_level'] == 1):		
			$this->debug_sql($sql);
		endif;
		
		$this->query($sql);
		
		//print_r($this->result);
		$row = mysql_fetch_assoc($this->new_result);
		
		return $row;
	}

}

?>
