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
 * Entity Install Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class owa_processEventQueueController extends owa_cliController {

    function __construct( $params ) {

        $this->setRequiredCapability( 'edit_modules' );
        return parent::__construct( $params );
    }

    function action() {

        if ( $this->getParam( 'queues' ) ) {

            $queues = $this->getParam( 'queues' );

        } else {

            $queues = 'incoming_tracking_events,processing';
        }

        owa_coreAPI::notice( "About to process event queues: $queues");

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

                $q = owa_coreAPI::getEventQueue( $queue_name );

                if ( $q->connect() ) {

                    $d = owa_coreAPI::getEventDispatch();
                    $more = true;

                    while( $more ) {

                        owa_coreAPI::debug( 'calling receive message' );
                        // get an item from the queue
                        $event = $q->receiveMessage();
                        owa_coreAPI::debug( 'Event returned: '.print_r( $event, true ) );

                        if ( $event ) {

                            // process event if needed
                            // lookup which event processor to use to process this event type
                            $processor_action = owa_coreAPI::getEventProcessor( $event->getEventType() );

                            if ( $processor_action ) {

                                // processor handles it's own event dispatching, so just return
                                return owa_coreAPI::handleRequest( array( 'event' => $event ), $processor_action );

                            } else {

                                // dispatch event
                                $ret = $d->notify( $event );
                            }

                            if ( $ret  = OWA_EHS_EVENT_HANDLED ) {
                                // delete event from queue
                                // second param is for backwards compat. remove soon
                                $q->deleteMessage( $event->getQueueGuid() );
                            }

                        } else {
                            // if no event, stop the loop
                            $more = false;
                            owa_coreAPI::notice("No more events to process.");
                        }
                    }

                    $q->disconnect();
                }
            }

        } else {

            owa_coreAPI::notice("There are no event queues registered.");
        }
    }
}

?>