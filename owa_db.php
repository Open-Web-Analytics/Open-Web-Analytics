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

require 'owa_error.php';

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
	 * Error Logger
	 * 
	 * @return object
	 * @access private
	 */
	var $e;

	/**
	 * Constructor
	 *
	 * @return 	owa_db
	 * @access 	public
	 */
	function owa_db() {
	
		$this->config = &owa_settings::get_settings();
		$this->e = &owa_error::get_instance();
		
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
			$this->config = &owa_settings::get_settings();
			$this->e = &owa_error::get_instance();
			
			$connection_class = "owa_db_" . $this->config['db_type'];
			$connection_class_path = $this->config['db_class_dir'] . $connection_class . ".php";
	
	 		if (!@include($connection_class_path)):
	 			$this->e->emerg(sprintf('Cannot locate proper db class at %s. Exiting.',
	 							$connection_class_path));
	 			exit;
			else:  	
				$db = new $connection_class;
				$this->e->debug(sprintf('Using db class at %s.',
	 							$connection_class_path));
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

}

?>
