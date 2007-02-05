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
	
	function addDefaultSite() { 
		
		$site = new owa_site;
		$site->name = $this->params['name'];
		$site->description = $this->params['description'];
		if (!empty($site->site_family)):
			$site->site_family = $this->params['description'];
		else:
			$site->site_family = 1;
		endif;
		$site_id = $site->save();
		
		return;
	}
	
}

?>