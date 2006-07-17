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

/**
 * Host Entity
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_host {
	
	var $config;
	
	var $e;
	
	var $db;
	
	var $properties;
	
	function owa_host() {
		
		$this->config = &owa_settings::get_settings();
		$this->db = &owa_db::get_instance();
		$this->e = &owa_error::get_instance();
		
		return;
		
	}
	
	function get() {
		
		return;
	}
	
	function save() {
		
		return $this->db->query(sprintf("
								INSERT INTO %s 
									(id, host, full_host, ip_address)
								VALUES
									('%s', '%s', '%s', '%s')
								",
								$this->config['ns'].$this->config['hosts_table'],
								$this->properties['host_id'],
								$this->db->prepare($this->properties['host']),
								$this->db->prepare($this->properties['full_host']),
								$this->db->prepare($this->properties['ip_address']))
								);
		
	}
	
	
	
	
}

?>