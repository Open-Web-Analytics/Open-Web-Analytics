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
 * Install Configuration Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_installConfigController extends owa_installController {
		
	function __construct($params) {
		
		parent::__construct($params);
		
		// require nonce
		$this->setNonceRequired();
		
		//required params
		$v1 = owa_coreAPI::validationFactory('required');
		$v1->setValues($this->getParam('db_host'));
		$v1->setErrorMessage("Database host is required.");
		$this->setValidation('db_host', $v1);
		
		$v2 = owa_coreAPI::validationFactory('required');
		$v2->setValues($this->getParam('db_name'));
		$v2->setErrorMessage("Database name is required.");
		$this->setValidation('db_name', $v2);
		
		$v3 = owa_coreAPI::validationFactory('required');
		$v3->setValues($this->getParam('db_user'));
		$v3->setErrorMessage("Database user is required.");
		$this->setValidation('db_user', $v3);
		
		$v4 = owa_coreAPI::validationFactory('required');
		$v4->setValues($this->getParam('db_password'));
		$v4->setErrorMessage("Database password is required.");
		$this->setValidation('db_password', $v4);
		
		$v7 = owa_coreAPI::validationFactory('required');
		$v7->setValues($this->getParam('db_type'));
		$v7->setErrorMessage("Database type is required.");
		$this->setValidation('db_type', $v7);
		
		// Config for the public_url validation
		$v5 = owa_coreAPI::validationFactory('subStringMatch');
		$v5->setConfig('match', '/');
		$v5->setConfig('length', 1);
		$v5->setValues($this->getParam('public_url'));
		$v5->setConfig('position', -1);
		$v5->setConfig('operator', '=');
		$v5->setErrorMessage("Your URL of OWA's base directory must end with a slash.");
		$this->setValidation('public_url', $v5);
		
		// Config for the domain validation
		$v6 = owa_coreAPI::validationFactory('subStringPosition');
		$v6->setConfig('substring', 'http');
		$v6->setValues($this->getParam('public_url'));
		$v6->setConfig('position', 0);
		$v6->setConfig('operator', '=');
		$v6->setErrorMessage("Please add http:// or https:// to the beginning of your public url.");
		$this->setValidation('public_url', $v6);
	}
	
	function action() {
		
		// define db connection constants using values submitted
		if ( ! defined( 'OWA_DB_TYPE' ) ) {
			define( 'OWA_DB_TYPE', $this->getParam( 'db_type' ) );
		}
		
		if ( ! defined( 'OWA_DB_HOST' ) ) {
			define('OWA_DB_HOST', $this->getParam( 'db_host' ) );
		}
		
		if ( ! defined( 'OWA_DB_NAME' ) ) {		
			define('OWA_DB_NAME', $this->getParam( 'db_name' ) );
		}

		if ( ! defined( 'OWA_DB_USER' ) ) {		
			define('OWA_DB_USER', $this->getParam( 'db_user' ) );
		}
		
		if ( ! defined( 'OWA_DB_PASSWORD' ) ) {
			define('OWA_DB_PASSWORD', $this->getParam( 'db_password' ) );
		}
		
		owa_coreAPI::setSetting('base', 'db_type', OWA_DB_TYPE);
		owa_coreAPI::setSetting('base', 'db_host', OWA_DB_HOST);
		owa_coreAPI::setSetting('base', 'db_name', OWA_DB_NAME);
		owa_coreAPI::setSetting('base', 'db_user', OWA_DB_USER);
		owa_coreAPI::setSetting('base', 'db_password', OWA_DB_PASSWORD);	
						
		// Check DB connection status
		$db = owa_coreAPI::dbSingleton();
		$db->connect();
		if ($db->connection_status != true) {
			$this->set('error_msg', $this->getMsg(3012));
			$this->set('config', $this->params);
			$this->setView('base.install');
			$this->setSubview('base.installConfigEntry');

		} else {
			//create config file
			$this->c->createConfigFile($this->params);
			$this->setRedirectAction('base.installDefaultsEntry');
		}
		
		// Check socket connection
		
		// Check permissions on log directory
		

		return;
	}	
	
	function errorAction() {
		$this->set('config', $this->params);
		$this->setView('base.install');
		$this->setSubview('base.installConfigEntry');
	}
}

?>