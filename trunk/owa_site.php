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
	
	var $config;
	
	var $db;
	
	var $e;
	
	var $name;
	
	var $description;
	
	var $site_family;
	
	var $site_id;
	
	var $id;
	
	function owa_site() {
		
		$this->config = &owa_settings::get_settings();
		$this->db = &owa_db::get_instance();
		$this->e = &owa_error::get_instance();
		
		return;
	}
	
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
	
	function addSite() {
		
		$insert = $this->db->query(sprintf("
								INSERT INTO %s 
									(site_id, name, description, site_family)
								VALUES
									('%s', '%s', '%s', '%s')
								",
								$this->config['ns'].$this->config['sites_table'],
								$this->site_id,
								$this->db->prepare($this->name),
								$this->db->prepare($this->description),
								$this->site_family));
		
		if ($insert == true):
			return $site_id;
		else:
			return false;
		endif;
		
	}
	
	function addNewSite() {
		
		$this->site_id = md5($this->name.rand().time());
		return $this->addSite();
		
		
	}
	
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