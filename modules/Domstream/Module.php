<?php
namespace OWA\Module\Domstream;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2016 Peter Adams. All rights reserved.
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
 * Remote Queue Module
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2016 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.6.1
 */

class Module extends \OWA\Core\Module {

    /**
     * Register this module's actions against their controllers.
     *
     * See Base\Module::registerActions() -- registration keeps
     * CoreAPI::performAction() on the safe branch instead of reconstructing a
     * class name and filesystem path from the request's own 'do' param.
     */
    function registerActions() {

        // NOTE: registerAction() prefixes $file with OWA_BASE_MODULE_DIR, which is
        // hardcoded to modules/Base/ -- so a non-Base module cannot express a
        // correct path through it. Pass none: the class name is the PSR-4 name,
        // so Composer autoloads it and simpleFactory() short-circuits on
        // class_exists() before the path is ever consulted.
        //
        // Not changing that prefix here on purpose: third-party modules calling
        // registerAction() today are passing Base-relative paths, and switching
        // it to $this->path would break them silently. registerRestApiRoute() is
        // the module-aware equivalent if this ever needs revisiting.
        $this->registerAction( 'domstream.domstreamsRest',
            'OWA\\Module\\Domstream\\Controller\\DomstreamsRestController',
            '' );
    }

    function __construct() {

        $this->name = 'domstream';
        $this->display_name = 'Domstream';
        $this->group = 'logging';
        $this->author = 'Peter Adams';
        $this->version = '1.0';
        $this->description = 'Logs the users mouse and other DOM movements.';
        $this->config_required = false;
        $this->required_schema_version = 1;

        // register named queues

        return parent::__construct();
    }

    function registerFilters() {

        // adds tracking cmd to js tracker.
        if ( \OWA\Core\CoreAPI::getSetting( 'domstream', 'is_active' ) ) {

            $this->registerFilter('tracker_tag_cmds', $this, 'addToTracker', 99);
        }
    }

    /**
     * Adds domstream logging to the JS tracker tag.
      * @return array
      */
    function addToTracker( $cmds ) {

        $cmds[] = "owa_cmds.push(['trackDomStream']);";

        return $cmds;
    }

    /**
     * Registers Event Handlers with queue queue
     *
     */
    function _registerEventHandlers() {

        $this->registerEventHandler('dom.stream', 'domstreamHandlers');
    }

    /**
     * Registers Reports in Main Navigation
     *
     */
    function registerNavigation() {

        $this->addNavigationLinkInSubGroup( 'Content', $this->reportRef( 'domstreams' ), 'Domstreams', 5);
    }

    /**
     * Register API methods
     *
     */
    function registerApiMethods() {
		
		$this->registerRestApiRoute( 'v1', 'domstreams', 'GET', 'OWA\\Module\\Domstream\\Controller\\DomstreamsRestController', 'Controller/DomstreamsRestController.php' );
    }
}