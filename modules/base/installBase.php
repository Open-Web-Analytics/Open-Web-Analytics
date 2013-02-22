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

require_once(OWA_BASE_CLASS_DIR.'installController.php');

/**
 * base Schema Installation Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_installBaseController extends owa_installController {
		
	function __construct($params) {
		
		parent::__construct($params);
		
		// require nonce
		$this->setNonceRequired();
		
		// validations
		$v1 = owa_coreAPI::validationFactory('required');
		$v1->setValues($this->getParam('domain'));
		$v1->setErrorMessage($this->getMsg(3309));
		$this->setValidation('domain', $v1);
		
		// validations
		$v2 = owa_coreAPI::validationFactory('required');
		$v2->setValues($this->getParam('email_address'));
		$v2->setErrorMessage($this->getMsg(3310));
		$this->setValidation('email_address', $v2);
		
		// validations
		$v5 = owa_coreAPI::validationFactory('required');
		$v5->setValues($this->getParam('password'));
		$v5->setErrorMessage($this->getMsg(3310));
		$this->setValidation('password', $v5);
		
		// Check entity exists
		$v3 = owa_coreAPI::validationFactory('entityDoesNotExist');
		$v3->setConfig('entity', 'base.site');
		$v3->setConfig('column', 'domain');
		$v3->setValues($this->getParam('protocol').$this->getParam('domain'));
		$v3->setErrorMessage($this->getMsg(3206));
		$this->setValidation('domain', $v3);
		
		// Config for the domain validation
		$v4 = owa_coreAPI::validationFactory('subStringPosition');
		$v4->setConfig('substring', 'http');
		$v4->setValues($this->getParam('domain'));
		$v4->setConfig('position', 0);
		$v4->setConfig('operator', '!=');
		$v4->setErrorMessage($this->getMsg(3208));
		$this->setValidation('domain', $v4);
	}
	
	function action() {
		
		$status = $this->installSchema();
				
		if ($status == true) {
			$this->set('status_code', 3305);
			
			$password = $this->createAdminUser($this->getParam('email_address'), '', $this->getParam('password') );
			
			$site_id = $this->createDefaultSite($this->getParam('protocol').$this->getParam('domain'));	
			
			// Set install complete flag. 
			$this->c->persistSetting('base', 'install_complete', true);
			$save_status = $this->c->save();
			
			if ($save_status == true) {
				$this->e->notice('Install Complete Flag added to configuration');
			} else {
				$this->e->notice('Could not add Install Complete Flag to configuration.');
			}
			
			// fire install complete event.
			$ed = owa_coreAPI::getEventDispatch();
			$event = $ed->eventFactory();
			$event->set('u', 'admin');
			$event->set('p', $password);
			$event->set('site_id', $site_id);
			$event->setEventType('install_complete');
			$ed->notify($event);
			
			// set view
			$this->set('u', 'admin');
			$this->set('p', $password);
			$this->set('site_id', $site_id);
			$this->setView('base.install');
			$this->setSubview('base.installFinish');
			//$this->set('status_code', 3304);
			
		} else {
	
			$this->set('error_msg', $this->getMsg(3302));
			$this->errorAction();
		}
	}
	
	function errorAction() {
	
		$this->set('defaults', $this->params);
		$this->setView('base.install');
		$this->setSubView('base.installDefaultsEntry');
	}
}

?>