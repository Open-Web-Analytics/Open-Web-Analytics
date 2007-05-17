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

	function owa_install() {
		
		$this->owa_base();
		$this->db = &owa_coreAPI::dbSingleton();
		
		return;
	}
	
	/**
	 * Check to see if schema is installed
	 *
	 * @return boolean
	 */
	function checkForSchema() {
		
		foreach ($this->tables as $table) {
			$this->e->notice('testing for existance of schema.');
			$check = $this->db->get_results(sprintf("show tables like '%s'", $table));
			$this->e->notice(print_r($check, true));
			
			if (!empty($check)):
				$this->e->notice(sprintf("Schema Installation aborted. Table '%s' already exists.", $table));
				return true;
			endif;
		}
		
		return false;
		
	}
	
}

?>