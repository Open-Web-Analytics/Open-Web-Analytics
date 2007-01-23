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
	 * Domain of web site
	 *
	 * @var unknown_type
	 */
	var $domain;
	
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
	
	
	function getSiteByPK($site_id) {

		return $this->getSite($site_id);
		
	}
	
	/**
	 * Retrieves the site from the database
	 *
	 * @param string $site_id
	 * @return array
	 */
	function getSite($site_id) {
		
		return $this->getSiteBase('site_id', $site_id);
	}
	
	function getSiteById($id) {
		
		return $this->getSiteBase('id', $id);
		
	}
	
	function getSiteByDomain($domain) {
		
		return $this->getSiteBase('domain', $domain);
		
	}
	
	function getSiteBase($constraint, $value) {
		
		$row = $this->db->get_row(sprintf("SELECT 
										id,
										site_id, 
										name, 
										domain,
										description, 
										site_family
									FROM
										%s
									WHERE
										%s = '%s'",
									$this->config['ns'].$this->config['sites_table'],
									$constraint,
									$value));
		if (!empty($row)):					
			$this->_setAttributes($row);
			return true;
		else:		
			return false;
		endif;
		
	}
	
	/**
	 * Sets user object attributes
	 *
	 * @param unknown_type $array
	 */
	function _setAttributes($array) {
		
		foreach ($array as $n => $v) {
				
				$this->$n = $v;
		
			}
		
		return;
	}
	
	
	function save() {
		
		return $this->addNewSite();
	}
	
	/**
	 * Adds a site to the database
	 *
	 * @return string Returns the site id
	 */
	function addSite() {
		
		$status =  $this->db->query(sprintf("
								INSERT INTO %s 
									(site_id, name, domain, description, site_family)
								VALUES
									('%s', '%s', '%s', '%s', '%s')
								",
								$this->config['ns'].$this->config['sites_table'],
								$this->site_id,
								$this->db->prepare($this->name),
								$this->db->prepare($this->domain),
								$this->db->prepare($this->description),
								$this->site_family));
								
								
		if ($status == true):
			$site_id = $this->site_id;
		else:
			$site_id = false;
		endif;
		
		return $site_id;
		
	}
	
	/**
	 * Generates a GUID for a new site and saves it to the db
	 *
	 * @return string Returns the site_id of the newly added web site.
	 */
	function addNewSite() {
		
		$this->site_id = md5($this->domain);
		return $this->addSite();
		
		
	}
	
	function saveSiteCustomId() {
		
		return $this->addSite();
		
	}
	
	function delete() {
		
		return $this->db->query(sprintf("DELETE FROM 
											%s
										WHERE
											site_id = '%s'",
								$this->config['ns'].$this->config['sites_table'],
							  	$this->site_id));
		
		
		
	}
	
	/**
	 * Updates a site's record i nthe db
	 *
	 * @param string $site_id
	 * @return boolean
	 */
	function updateSite($site_id) {
		
		return $this->db->query(sprintf("UPDATE 
											%s
										SET
											name = '%s',
											description = '%s',
											site_family = '%s'
										WHERE
											site_id = '%s'
										",
										$this->config['ns'].$this->config['sites_table'],
										$this->db->prepare($this->name),
										$this->db->prepare($this->description),
										$this->db->prepare($this->site_family),
										$this->db->prepare($this->site_id)
										));
		
	}
	
	function update() {
		
		return $this->updateSite($this->site_id);
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
													domain,
													site_family
												FROM
													%s",
													$this->config['ns'].$this->config['sites_table']
													));
	}
	
}


?>