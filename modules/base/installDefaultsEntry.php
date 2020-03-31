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
require_once(OWA_BASE_CLASS_DIR.'installController.php');

/**
 * Install Configuration Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_installDefaultsEntryController extends owa_installController {

    function action() {

        $this->setView('base.install');
        $this->setSubview('base.installDefaultsEntry');
    }
}

/**
 * Installer Defaults Entry
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.2.1
 */

class owa_installDefaultsEntryView extends owa_view {

    function render($data) {

        // page title
        $this->t->set('page_title', 'OWA User / Site Setup');
        // set defaults
        $this->body->set('defaults', $this->get('defaults'));
        // load body template
        $this->body->set_template('install_defaults_entry.php');
        $this->setJs("owa", "base/js/owa.js");
    }
}

?>