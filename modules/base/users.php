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

require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_CLASSES_DIR.'owa_coreAPI.php');

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
	
	function owa_usersView($params) {
		
		$this->owa_view($params);
		$this->priviledge_level = 'admin';
		
		return;
	}
	
	function construct() {
		
		//page title
		$this->t->set('page_title', 'User Roster');
		
		// load body template
		$this->body->set_template('users.tpl');
		
		// fetch admin links from all modules
		//
		
		$this->body->set('headline', 'User Roster');
		
		$u = owa_coreAPI::entityFactory('base.user');
		$params['constraints']['creation_date'] = array('operator' => '!=', 'value' => '0');
		$users = $u->find($params);
		
		$this->body->set('users', $users);
		
		return;
	}
	
	
}


?>