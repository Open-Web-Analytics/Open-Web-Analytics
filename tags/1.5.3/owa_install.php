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

require_once (OWA_BASE_DIR.'/owa_base.php');

/**
 * Install Abstract Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_install extends owa_base{
	
	/**
	 * Data access object
	 *
	 * @var object
	 */
	var $db;
	
	/**
	 * Version of string
	 *
	 * @var string
	 */
	var $version;
	
	/**
	 * Params array
	 *
	 * @var array
	 */
	var $params;
	
	/**
	 * Module name
	 *
	 * @var unknown_type
	 */
	var $module;
	
	/**
	 * Constructor
	 *
	 * @return owa_install
	 */

	function __construct() {
		
		parent::__construct();
		$this->db = owa_coreAPI::dbSingleton();
	}
	
	/**
	 * Check to see if schema is installed
	 *
	 * @return boolean
	 */
	function checkForSchema() {
		
		$table_check = array();
		//$this->e->notice(print_r($this->tables, true));
		// test for existance of tables
		foreach ($this->tables as $table) {
			$this->e->notice('Testing for existance of table: '. $table);
			$check = $this->db->get_results(sprintf("show tables like 'owa_%s'", $table));
			//$this->e->notice(print_r($check, true));
			
			// if a table is missing add it to this array
			if (empty($check)):
				$table_check[] = $table;
				$this->e->notice('Did not find table: '. $table);
			else:
				$this->e->notice('Table '. $table. ' already exists.');
			endif;
		}
		
		if (!empty($table_check)):
			//$this->e->notice(sprintf("Schema Check: Tables '%s' are missing.", implode(',', $table_check)));
			$this->e->notice(sprintf("Schema Check: Tables to install: %s", print_r($table_check, true)));

			return $table_check;
		else:	
			return false;
		endif;
		
	}
	
}

?>