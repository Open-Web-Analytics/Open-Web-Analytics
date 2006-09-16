<?

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

require_once(OWA_BASE_DIR.'/owa_db.php');

/**
 * Web Site Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_site {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config;
	
	/**
	 * Data Access object
	 *
	 * @var object
	 */
	var $db;
	
	/**
	 * Error handler
	 *
	 * @var object
	 */
	var $e;
	
	/**
	 * Name of web site
	 *
	 * @var string
	 */
	var $name;
	
	/**
	 * Description of web site
	 *
	 * @var unknown_type
	 */
	var $description;
	
	/**
	 * Family that web site belongs to
	 *
	 * @var string
	 */
	var $site_family;
	
	/**
	 * GUID for the web site 
	 *
	 * @var string
	 */
	var $site_id;
	
	/**
	 * Databse ID of the web site
	 *
	 * @var unknown_type
	 */
	var $id;
	
	/**
	 * Constructor
	 *
	 * @return owa_site
	 */
	function owa_site() {
		
		$this->config = &owa_settings::get_settings();
		$this->db = &owa_db::get_instance();
		$this->e = &owa_error::get_instance();
		
		return;
	}
	
	/**
	 * Retrieves the site from the database
	 *
	 * @param string $site_id
	 * @return array
	 */
	function getSite($site_id) {
		
		$row = $this->db->get_row(sprintf("SELECT 
										id, 
										name, 
										description, 
										site_family
									FROM
										%s
									WHERE
										site_id = '%s'",
									$this->config['ns'].$this->config['sites_table'],
									$site_id));
		if (!empty($row)):					
			$this->id = $row['id'];
			$this->site_id = $site_id;
			$this->site_name = $row['name'];
			$this->description = $row['description'];
			$this->site_family = $row['site_family'];
			return true;
		else:		
			return false;
		endif;
	}
	
	
	function save() {
		
		return $this->addSite();
	}
	
	/**
	 * Adds a site to the database
	 *
	 * @return string Returns the site id
	 */
	function addSite() {
		
		$insert = $this->db->query(sprintf("
								INSERT INTO %s 
									(name, description, site_family)
								VALUES
									('%s', '%s', '%s')
								",
								$this->config['ns'].$this->config['sites_table'],
								$this->db->prepare($this->name),
								$this->db->prepare($this->description),
								$this->site_family));
		
		if ($insert == true):
			return $this->site_id;
		else:
			return false;
		endif;
		
	}
	
	/**
	 * Generates a GUID for a new site and saves it to the db
	 *
	 * @return string Returns the site_id of the newly added web site.
	 */
	function addNewSite() {
		
		//$this->site_id = md5($this->name.rand().time());
		return $this->addSite();
		
		
	}
	
	/**
	 * Updates a site's record i nthe db
	 *
	 * @param string $site_id
	 * @return boolean
	 */
	function updateSite($site_id) {
		
		return $this->db->query(sprintf("UPDATE 
											%S
										SET
											name = '%s'
											AND description = '%s'
										WHERE
											site_id = '%s'
										",
										$this->config['ns'].$this->config['sites_table'],
										$this->db->prepare($this->name),
										$this->db->prepare($this->description),
										$this->db->prepare($this->site_family)
										));
		
	}
	
	/**
	 * Get  list of all sites from the db
	 *
	 * @return array
	 */
	function getAllSites() {
		
		return $this->db->get_results(sprintf("
												SELECT
													site_id,
													name,
													description,
													site_family
												FROM
													%s",
													$this->config['ns'].$this->config['sites_table']
													));
	}
	
}


?>