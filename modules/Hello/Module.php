<?php
namespace OWA\Module\Hello;


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


/**
 * Hello World Module
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Module extends \OWA\Core\Module {


    function __construct() {

        $this->name = 'hello';
        $this->display_name = 'Hello World';
        $this->group = 'hello';
        $this->author = 'Peter Adams';
        $this->version = '1.0';
        $this->description = 'Hello world sample module.';
        $this->config_required = false;
        $this->required_schema_version = 1;

        return parent::__construct();
    }

    /**
     * Registers Admin panels with the core API
     *
     */
    function registerAdminPanels() {

        $this->registerSettingsPage(array( 'do'    => 'hello.exampleSettings',
                                          'title' => 'Hello World!',
                                          'group' => 'Test',
                                          'order' => 1));


        return;

    }

    /**
     * The report this module ships.
     *
     * A definition file, which is how a module adds a report -- see
     * reports/hello-dashboard.json. This example previously pointed its
     * navigation at hello.reportDashboard and hello.reportSearchterms, neither
     * of which was registered anywhere, so both links errored: the module a
     * third party copies was demonstrating a shape that does not work.
     */
    function registerReports() {

        $this->registerReport( 'hello-dashboard', 'reports/hello-dashboard.json' );
    }

    public function registerNavigation() {

        // reportRef() builds the link to a registered report, so the nav cannot
        // name a report that does not exist.
        $this->addNavigationSubGroup(
            'Hello World', $this->reportRef( 'hello-dashboard' ), 'Hello Dashboard' );
    }

    /**
     * Registers Event Handlers with queue queue
     *
     */
    function _registerEventHandlers() {


        // Clicks
        //$this->_addHandler('base.click', 'clickHandlers');

        return;

    }

    function _registerEntities() {

        //$this->entities[] = 'myentity';
    }


}


?>