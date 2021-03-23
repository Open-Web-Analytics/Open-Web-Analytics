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

require_once(OWA_BASE_DIR.'/owa_adminController.php');

/**
 * Delete User Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_usersDeleteController extends owa_adminController {

    function __construct($params) {

        $this->setRequiredCapability('edit_users');
        $this->setNonceRequired();
        return parent::__construct($params);
    }
    
    function validate() {
	    
	    $this->addValidation('user_id', $this->getParam('user_id'), 'required', array('stopOnError'	=> true));
	    $this->addValidation('user_id', $this->getParam('user_id'), 'isNotCurrentUser');
    }

    function action() {

        $userManager = owa_coreApi::supportClassFactory('base', 'userManager');

        // add check here to ensure that this is not the default user....
        $userManager->deleteUser($this->getParam('user_id'));
    }
    
    function success() {
	    
	    $this->setRedirectAction('base.users');
        $this->set('status_code', 3004);
    }
}

?>