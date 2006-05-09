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
 * Installs core database schema
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_install {
	
	function get_instance() {
		
	}
	
	function create_all_tables() {
	
		$this->create_requests_table();
		$this->create_sessions_table();
		$this->create_referers_table();
		$this->create_documents_table();
		$this->create_ua_table();
		$this->create_hosts_table();
		$this->create_os_table();
		$this->create_optinfo_table();
		$this->create_settings_table();
		
		$this->config['schema_version'] = $this->version;
		$this->config->save();
	
		return;
	}
}

?>