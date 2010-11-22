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
 * Users Roster View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_usersController extends owa_adminController {
		
	function __construct($params) {
		
		$this->setRequiredCapability('edit_users');
		return parent::__construct($params);
	}
	
	function action() {
		
		$db = owa_coreAPI::dbSingleton();
		$db->selectFrom('owa_user');
		$db->selectColumn("*");
		$users = $db->getAllRows();
		$this->set('users', $users);
		$this->setView('base.options');
		$this->setSubview('base.users');
	}
}


/**
 * Users Roster View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_usersView extends owa_view {
		
	function render() {
		
		//page title
		$this->t->set('page_title', 'User Roster');
		$this->body->set_template('users.tpl');
		$this->body->set('headline', 'User Roster');
		$this->body->set('users', $this->get('users'));
	}
}

?>