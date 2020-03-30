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

require_once(OWA_BASE_CLASS_DIR.'cliController.php');

/**
 * Prune Event Queues Archive CLI Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.6.0
 */

class owa_pruneEventQueueArchivesCliController extends owa_cliController {

    function __construct($params) {

        $this->setRequiredCapability('edit_modules');
        return parent::__construct($params);
    }

    function action() {

        if ( $this->getParam( 'queues' ) ) {

            $queues = $this->getParam( 'queues' );

        } else {

            $queues = 'incoming_tracking_events,processing';
        }

        if ( $this->getParam( 'interval' ) ) {

            $interval = $this->getParam( 'interval' );

        } else {

            $interval = 3600*24;
        }

        // pull list of event queues to process from command line
        $queues = $this->getParam( 'queues' );

        if ( $queues ) {
            // parse command line
            $queues = explode( ',', $this->getParam( 'queues' ) );

        } else {

            // get whatever queues are registered by modules
            $s = owa_coreAPI::serviceSingleton();
            $queues = array_keys( $s->getMap('event_queues') );
        }

        if ( $queues ) {

            foreach ( $queues as $queue_name ) {

                owa_coreAPI::notice( "About to prune archive of event queue: $queue_name");

                $q = owa_coreAPI::getEventQueue($queue_name);

                if ( $q->connect() ) {
                    $q->pruneArchive( $interval );
                }
            }
        }
    }
}

?>