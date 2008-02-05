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
require_once(OWA_BASE_DIR.'/owa_controller.php');
require_once(OWA_BASE_DIR.'/owa_coreAPI.php');

/**
 * Installation Finish
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_installFinishController extends owa_controller {

	function owa_installFinishController($params) {
		$this->owa_controller($params);
		
		// Secure access to this controller if the installer has already been run
		if ($this->c->get('base', 'install_complete') != true):	
			$this->priviledge_level = 'guest';
		else:
			$this->priviledge_level = 'admin';
		endif;
	}
	
	function action() {
	
		// Persist install complete flag. 
		$this->c->setSetting('base', 'install_complete', true);
		$save_status = $this->c->save();
		
		if ($save_status == true):
			$this->e->notice('Install Complete Flag added to configuration');
		else:
			$this->e->notice('Could not persist Install Complete Flag to the Database');
		endif;
		
		
		$site = owa_coreAPI::entityFactory('base.site');
		
		$site->getByPk('id', '1');
		
		$data = array();
		$data['view'] = 'base.install';
		$data['subview'] = 'base.installFinish';
		$data['view_method'] = 'delegate';
		$data['site_id'] = $site->get('site_id');
		$data['status_code'] = $this->params['status_code']; 
		$data['u'] = $this->params['u'];
		$data['k'] = $this->params['k'];
		
		return $data;
	}
	
	

}


class owa_installFinishView extends owa_view {
	
	function owa_installFinishView() {
		
		$this->owa_view();
		$this->priviledge_level = 'guest';
		
		return;
	}
	
	function construct($data) {
		
		$api = &owa_coreAPI::singleton();
		
		// Set Page title
		$this->t->set('page_title', 'Installation Complete');
		
		// Set Page headline
		$this->body->set('headline', 'Installation is Complete');
		
		$this->body->set('site_id', $data['site_id']);
		$this->body->set('u', $data['u']);
		$this->body->set('key', $data['k']);
		// load body template
		$this->body->set_template('install_finish.tpl');
		
		
		
		return;
	}
	
	
}





?>