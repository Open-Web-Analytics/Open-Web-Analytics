<?php
namespace OWA\Module\Base\Classes;


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
 * Database backed Event Queue Implementation
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class DbEventQueue extends \owa_eventQueue {
    
    var $db;
    var $items_per_fetch = 50;
        
    function __construct( $map = array() ) {
    
        return parent::__construct( $map );
    }
    
    function connect() {
        
        $this->db = \owa_coreAPI::dbSingleton();
        \owa_coreAPI::debug('Connected to event queue.');
        return true;
    }
        
    function sendMessage( $event ) {
        
        $qi = \owa_coreAPI::entityFactory('base.queue_item');
        
        $qi->getByPk( 'id',  $event->getGuid() );
        
        if ( ! $qi->wasPersisted() ) {
            
            $qi->set( 'id', $event->getGuid() );
            $qi->set( 'insertion_timestamp', $this->makeTimestamp() );
            $qi->set( 'insertion_datestamp', $this->makeDatestamp() );
        }

        $qi->set( 'event_type', $event->getEventType() );
        $qi->set( 'status', $event->getStatus() );
        $qi->set( 'priority', $this->determinPriority( $event->getEventType() ) );
        $qi->set( 'event', $this->prepareMessage( $event ) );
        $qi->set( 'failed_attempt_count' , $event->getReceiveCount() ); // need to rename this column to received count
        $qi->set( 'last_error_msg', $event->getErrorMsg() );
        $qi->set( 'last_attempt_timestamp', $event->getLastReceiveTimestamp() );
        
        // set do not receive before timestamp
        $not_before = $event->getDoNotReceiveBeforeTimestamp();
        
        // backwards compatability, should remove this soon.
        if ( ! $not_before && $event->getReceiveCount() != 0 ) {
            $not_before = $this->determineNextAttempt( $qi->get('event_type'), $event->getReceiveCount() );
        }
        
        if ( $not_before ) {
            $qi->set( 'not_before_timestamp', $not_before );
        }
                
        $qi->save();
    }
    
    function receiveMessage() {
        \owa_coreAPI::debug('getting message');
        $msg = $this->getNextItem();
        
        if ( $msg ) {
            $event = $this->decodeMessage( $msg->get('event') );
            // backwards compat. remove soon.
            $event->setOldQueueId( $msg->get('id') );
            // increment the count of times the event has been received from the
            // queue, and the timestamps of when it was last received. Called
            // once per receive -- a duplicate call here previously double-counted
            // every attempt, throwing off retry accounting.
            $event->wasReceived();

            return $event;
        }
    }
    
    function deleteMessage( $id ) {
        
        return $this->markAsHandled( $id );
    }
        
    function markAsHandled( $item_id ) {

        $qi = \owa_coreAPI::entityFactory('base.queue_item');
        $qi->load( $item_id );

        if ( $qi->wasPersisted() ) {
            $qi->set( 'status', 'handled' );
            $qi->set( 'handled_timestamp', $this->makeTimestamp() );
            $qi->save();
        } else {
            \owa_coreAPI::notice("Could not find/delete queue item id: $item_id");
        }
    }

    /**
     * Mark a queued item as permanently broken.
     *
     * Used when an event has exhausted its retry budget (see
     * hasExhaustedRetries). The row leaves the 'unhandled' working set so
     * getNextItems() stops returning it, but is retained (not deleted) so the
     * failure can be inspected. flushHandledEvents() only removes 'handled'
     * rows, so a separate prune can clear broken rows later.
     */
    function markAsBroken( $item_id, $error_msg = '' ) {

        $qi = \owa_coreAPI::entityFactory('base.queue_item');
        $qi->load( $item_id );

        if ( $qi->wasPersisted() ) {
            $qi->set( 'status', 'broken' );
            $qi->set( 'handled_timestamp', $this->makeTimestamp() );
            if ( $error_msg ) {
                $qi->set( 'last_error_msg', $error_msg );
            }
            $qi->save();
            \owa_coreAPI::notice("Marked queue item $item_id as broken after exhausting retries.");
        } else {
            \owa_coreAPI::notice("Could not find/mark-broken queue item id: $item_id");
        }
    }

    /**
     * Whether a received event has exhausted its retry budget and should be
     * given up on (marked broken) rather than retried again.
     *
     * A permanently-failing event (e.g. a session_update whose session never
     * persists, or an event for an unregistered site) would otherwise be
     * re-queued on every run forever, accumulating in owa_queue_item. It is
     * exhausted when it exceeds EITHER cap:
     *   - queue_max_retry_count: number of failed receive attempts
     *   - queue_max_retry_age:   seconds since the item was first queued
     * Either cap set to 0 disables that check. This is checked BEFORE the
     * current attempt is counted, so an event genuinely gets max_retry_count
     * attempts before being marked broken.
     */
    function hasExhaustedRetries( $event ) {

        $max_count = (int) \owa_coreAPI::getSetting( 'base', 'queue_max_retry_count' );
        $max_age   = (int) \owa_coreAPI::getSetting( 'base', 'queue_max_retry_age' );

        // getReceiveCount() reflects prior attempts: receiveMessage() calls
        // wasReceived() before the event is handed to the processor, so on the
        // Nth drain the count is N. Give up once prior attempts meet the cap.
        if ( $max_count > 0 && $event->getReceiveCount() > $max_count ) {
            return true;
        }

        if ( $max_age > 0 ) {

            // The queued row's id is the event's queue guid; read its
            // insertion_timestamp to measure elapsed time in the queue.
            $qi = \owa_coreAPI::entityFactory('base.queue_item');
            $qi->load( $event->getQueueGuid() );

            if ( $qi->wasPersisted() ) {

                $inserted = (int) $qi->get( 'insertion_timestamp' );

                if ( $inserted > 0 && ( $this->makeTimestamp() - $inserted ) > $max_age ) {
                    return true;
                }
            }
        }

        return false;
    }
    
    function getNextItems($limit = '') {
        
        if ( ! $limit ) {
            $limit = $this->items_per_fetch;
        }
        $this->db->select( '*' );
        $this->db->from( 'owa_queue_item' );
        $this->db->where( 'status', 'unhandled' );
        $this->db->where( 'not_before_timestamp', time(), '<' );
        $this->db->orderBy( 'insertion_timestamp' , 'ASC' );
        $this->db->limit( $limit );
        
        $items = $this->db->getAllRows();
        
        if ( $items ) {
            $entities = array();
            foreach ( $items as $item ) {
                $qi = \owa_coreAPI::entityFactory( 'base.queue_item' );
                $qi->setProperties( $item );
                $entities[] = $qi;
            }
            
            if ( $limit > 1 ) {
                return $entities;
            } else {
                return $entities[0];
            }
        }
    }
    
    function flushHandledEvents() {
        
        $this->db->deleteFrom( 'owa_queue_item' );
        $this->db->where( 'status' , 'handled');
        $ret = $this->db->executeQuery();
        return $this->db->getAffectedRows();
    }
    
    /**
     * Prune the event archive
     * @todo make an event archive table
     * @todo modify flushHandledEvents to move handled events to an archive.
     */
    function pruneArchive( $interval ) {
        
        return true;
    }
    
    function getNextItem() {
    
        return $this->getNextItems(1);
    }
    
    function determineNextAttempt($event_type, $failed_count) {
    
        return $this->makeTimeStamp() +30;
    }
    
    function makeTimestamp() {
        
        return time();
    }
    
    // safe for mysql timestamp column type
    function makeDatestamp($time = '') {
        
        if ( ! $time ) {
            $time = time();
        }
        
        return gmdate("Y-m-d H:i:s", $time);
    }
    
    function determinPriority($event_type) {
        
        return 99;
    }
}

?>