<?php

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

require_once(OWA_BASE_DIR.'/owa_module.php');

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

class owa_domstreamModule extends owa_module {

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
        if ( owa_coreAPI::getSetting( 'domstream', 'is_active' ) ) {

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

        $this->addNavigationLinkInSubGroup( 'Content', 'base.reportDomstreams', 'Domstreams', 5);
    }

    /**
     * Register API methods
     *
     */
    function registerApiMethods() {
		
		$this->registerRestApiRoute( 'v1', 'domstreams', 'GET', 'owa_domstreamsRestController', 'controllers/domstreamsRestController.php' );
    }
}