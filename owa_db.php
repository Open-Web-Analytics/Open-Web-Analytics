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
class owa_db {
	
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
	 * Configuration
	 *
	 * @var array
	 */
	var $config = array();
	
	/**
	 * Debug
	 *
	 * @var string
	 */
	var $debug;
	
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
	 * Constructor
	 *
	 * @return 	owa_db
	 * @access 	public
	 */
	function owa_db() {
	
		$this->config = &wa_settings::get_settings();
		$this->debug = &owa_lib::get_debugmsgs();
		
		return;
	}

	/**
	 * Connection object factory
	 *
	 * @return 	object
	 * @access 	public
	 * @static 
	 */
	function &get_instance() {
	
		static $db;
	
		if (!isset($db)):
		
			$this->config = &wa_settings::get_settings();
			
			$connection_class = "owa_db_" . $this->config['db_type'];
			$connection_class_path = $this->config['db_class_dir'] . $connection_class . ".php";
	
	 		if (!@include($connection_class_path)):
  				print "error locating proper db class at $connection_class_path"; //error
			else:  	
				$db = new $connection_class;
			endif;	
		endif;
	
		return $db;
	}

	/**
	 * Print Debug
	 *
	 * @param 	string $sql
	 * @access 	public
	 */
	function debug_sql($sql) {
	
		$this->debug = $this->debug.(sprintf(
		  '<table class="debug" border="1" width="100%%"><tr><td valign="top" width="">%s</td><td valign="top">%s</td></tr>',
	
		  ++$this->num_queries,
		  $sql
		  
		));
		
		return;
	}
  	
	/**
	 * Iniital async DB query
	 *
	 * @deprecated 
	 * @param 	string $sql
	 * @return 	array
	 * @todo 	remove from this file
	 */
	function async_query($sql) {
	
		$async_db = new owa_db_async;
		$result = $async_db->query($sql);
		
		return $result;
	
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

}

?>
