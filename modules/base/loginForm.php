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
require_once(OWA_BASE_DIR.'/owa_controller.php');

/**
 * Login Form Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class owa_loginFormController extends owa_controller {

    function __construct($params) {

        return parent::__construct($params);
    }

    function action() {

        $cu = owa_coreAPI::getCurrentUser();

        $this->set('go', $this->getParam('go'));
        $this->set('user_id', $cu->getUserData('user_id'));
        $this->setView('base.loginForm');
    }
}

/**
 * Login Form View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_loginFormView extends owa_view {

    function __construct() {

        return parent::__construct();
    }

    function construct($data) {

        $this->setTitle("Login");
        $this->t->set_template('wrapper_public.tpl');
        $this->body->set_template('login_form.tpl');
        $this->body->set('headline', 'Please login using the from below');
        $this->body->set('user_id', $this->get('user_id'));
        $this->body->set('go', owa_sanitize::cleanUrl( $this->get('go') ) );
        $this->setJs("owa", "base/js/owa.js");
    }
}

?>