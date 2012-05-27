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

require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_adminController.php');

/**
 * Site Profile Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_sitesProfileController extends owa_adminController {
	
	function __construct($params) {
		
		$this->setRequiredCapability('edit_sites');
		return parent::__construct($params);
	}
	
	function action() {
		
		// needed as this controller is 
		$site_id = $this->getParam('siteId');
		if (!empty($site_id)) {
			$site = owa_coreAPI::entityFactory('base.site');
			$site->getByColumn('site_id', $site_id);
			$site_data = $site->_getProperties();
			$this->set('config', $site->get('settings') );
			$this->set('edit', $this->getParam('edit'));
			
		} else {
			$site_data = array();
		}
		
	
		
		$this->set('site', $site_data);
		$this->set('siteId', $site_id);
		$this->setView('base.options');
		$this->setSubview('base.sitesProfile');
	}
	

}


/**
 *  Sites Profile View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_sitesProfileView extends owa_view {
			
	function render() {
	
	
		$site = $this->get('site');
		if ($this->get('edit')) {
			$this->body->set('action', 'base.sitesEdit');
			$this->body->set('headline', 'Edit Site Profile for: '. $site['domain'] );
			
			$siteEntity = owa_coreAPI::entityFactory('base.site');
			$siteEntity->getByColumn('site_id', $this->get('siteId'));
			$this->body->set('siteEntity', $siteEntity);

		} else {
			$this->body->set('action', 'base.sitesAdd');
			$this->body->set('headline', 'Add a New Tracked Site Profile');
		
		}
		if (isset($site['domain'])) {
			$this->t->set( 'page_title', 'Site Profile for: '.  $site['domain'] );
		}
		else {
			$this->t->set( 'page_title', 'Site Profile for new Site');
		}
		
		$this->body->set('users', $this->getAllUserRows());
			
		$this->body->set( 'site', $site );
		$this->body->set( 'edit', $this->get('edit') );
		$this->body->set( 'site_id', $this->get('siteId') );
		$this->body->set( 'config', $this->get('config') );
		//print_r($this->get('config'));
		$this->body->set_template( 'sites_addoredit.tpl' );	
	}
	
	/**
	 * @return array
	 */
	private function getAllUserRows() {
		$db = owa_coreAPI::dbSingleton();
		$db->selectFrom('owa_user');
		$db->selectColumn("*");
		return $db->getAllRows();
	}
	
}



?>
