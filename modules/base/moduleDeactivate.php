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

require_once(OWA_BASE_CLASSES_DIR.'owa_adminController.php');

/**
 * Module Deactivation Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_moduleDeactivateController extends owa_adminController {

    function __construct($params) {

        $this->setRequiredCapability('edit_modules');
        $this->setNonceRequired();

        return parent::__construct($params);

    }

    function action() {

        $s = owa_coreAPI::serviceSingleton();
        $m = $s->getModule($this->getParam('module'));
        $m->deactivate();
        $this->setRedirectAction('base.optionsModules');
        $this->setStatusCode(2502);
    }

}

?>