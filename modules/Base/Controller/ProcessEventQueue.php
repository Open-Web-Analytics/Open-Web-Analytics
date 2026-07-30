<?php
namespace OWA\Module\Base\Controller;


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

class ProcessEventQueue extends \OWA\Core\Controller\Cli {

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

        \OWA\Core\CoreAPI::notice( "About to process event queues: $queues");

        // pull list of event queues to process from command line
        $queues = $this->getParam( 'queues' );

        if ( $queues ) {
            // parse command line
            $queues = explode( ',', (string) $this->getParam( 'queues' ) );

        } else {

            // get whatever queues are registered by modules
            $s = \OWA\Core\CoreAPI::serviceSingleton();
            $queues = array_keys( $s->getMap('event_queues') );
        }

        if ( $queues ) {

            foreach ( $queues as $queue_name ) {

                $q = \OWA\Core\CoreAPI::getEventQueue( $queue_name );

                if ( $q->connect() ) {

                    $d = \OWA\Core\CoreAPI::getEventDispatch();
                    $more = true;

                    while( $more ) {

                        \OWA\Core\CoreAPI::debug( 'calling receive message' );
                        // get an item from the queue
                        $event = $q->receiveMessage();
                        \OWA\Core\CoreAPI::debug( 'Event returned: '.print_r( $event, true ) );

                        if ( $event ) {

                            \OWA\Core\CoreAPI::debug('received event from queue');

                            // Give up on an event that has exhausted its retry
                            // budget (see dbEventQueue::hasExhaustedRetries): mark
                            // it broken -- retained for inspection but out of the
                            // unhandled working set -- rather than dispatching it.
                            // Dispatching a still-failing event would re-queue it
                            // (owa_eventDispatch::notify re-queues on failure) and
                            // it would retry forever, accumulating in the queue.
                            if ( $q->hasExhaustedRetries( $event ) ) {

                                \OWA\Core\CoreAPI::notice( 'Giving up on queue item '.$event->getQueueGuid().': retries exhausted.' );
                                $q->markAsBroken( $event->getQueueGuid(), 'Retries exhausted.' );
                                continue;
                            }

                            // process event if needed
                            // lookup which event processor to use to process this event type
                            $processor_action = \OWA\Core\CoreAPI::getEventProcessor( $event->getEventType() );

                            if ( $processor_action ) {

                                \OWA\Core\CoreAPI::debug("event directly handled");
                                // A processor runs the full request pipeline for the
                                // event (persistence + its own internal dispatch, which
                                // re-queues any handler failure as a fresh queue item)
                                // and returns controller data, not an event-handling
                                // status code. Reaching here without an exception means
                                // the event was consumed, so remove it from the queue.
                                \OWA\Core\CoreAPI::handleRequest( [ 'event' => $event ], $processor_action );
                                $q->deleteMessage( $event->getQueueGuid() );

                            } else {

                                // dispatch event to its registered handlers.
                                // notify() re-queues the event (as unhandled, with an
                                // incremented attempt count and a back-off) when any
                                // handler fails, so only remove it from the queue when
                                // it was actually handled -- otherwise the retry is
                                // cancelled. NOTE: the guard here was historically
                                // `if ( $ret = OWA_EHS_EVENT_HANDLED )` -- an
                                // assignment, not a comparison, so it was always true
                                // and every failed event was deleted after a single
                                // attempt, defeating the retry/back-off machinery.
                                $ret = $d->notify( $event );

                                if ( $ret !== OWA_EHS_EVENT_FAILED ) {
                                    // second param is for backwards compat. remove soon
                                    $q->deleteMessage( $event->getQueueGuid() );
                                }
                            }

                        } else {
                            // if no event, stop the loop
                            \OWA\Core\CoreAPI::notice("No more events to process.");
                            $more = false;
                            
                        }
                    }

                    $q->disconnect();
                }
            }

        } else {

            \OWA\Core\CoreAPI::notice("There are no event queues registered.");
        }
    }
}

?>