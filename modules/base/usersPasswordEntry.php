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

require_once(OWA_BASE_DIR.'/owa_controller.php');
require_once(OWA_BASE_DIR.'/owa_view.php');

/**
 * Change Password Controller
 * 
 * handles from input from the Change password screen
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_usersPasswordEntryController extends owa_controller {

    function __construct($params) {

        return parent::__construct($params);
    }

    function action() {

        $this->set('key', $this->getParam('k'));
        $this->setView('base.usersPasswordEntry');
        
        // needed for old style embedded install migration
         $this->set('is_embedded', $this->getParam('is_embedded'));
    }


}

/**
 * Change Password View
 * 
 * Presents a simple form to the user asking them to enter a new password.
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_usersPasswordEntryView extends owa_view {

    function __construct() {

        return parent::__construct();
    }

    function render($data) {

        $this->t->set_template('wrapper_public.tpl');
        $this->t->set('page_title', 'OWA Password Entry'); 
        $this->body->set_template('users_change_password.tpl');
        $this->body->set('headline', $this->getMsg(3005));
        $this->body->set('key', $this->get('key'));
        $this->body->set('is_embedded', $this->get('is_embedded'));
    }
}

?>