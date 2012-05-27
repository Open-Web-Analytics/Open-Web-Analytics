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

if ( ! class_exists( 'eventQueue' ) ) {
	require_once( OWA_BASE_CLASS_DIR.'eventQueue.php' );
}
/**
 * Database backed Event Queue Implementation
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_dbEventQueue extends eventQueue {
	
	var $db;
	var $items_per_fetch = 50;
		
	function __construct($queue_dir = '') {
		
		$this->db = owa_coreAPI::dbSingleton();
		return parent::__construct();	
	}
		
	function addToQueue($event) {
		
		$qi = owa_coreAPI::entityFactory('base.queue_item');
		$serialized_event = serialize( $event );
		$qi->set( 'id', $qi->generateId( $serialized_event) );
		$qi->set( 'event_type', $event->getEventType() );
		$qi->set( 'status', 'unhandled' );
		$qi->set( 'priority', $this->determinPriority( $event->getEventType() ) );
		$qi->set( 'event', $serialized_event );
		$qi->set( 'insertion_timestamp', $this->makeTimestamp() );
		$qi->set( 'insertion_datestamp', $this->makeDatestamp() );
		$qi->save();
	}
	
	function markAsFailed($item_id, $error_msg = '') {
		
		$qi = owa_coreAPI::entityFactory('base.queue_item');
		$qi->load($item_id);
		$inserted_timestamp = $qi->get('insertion_timestamp');
		if ($inserted_timestamp) {
			$qi->set( 'failed_attempt_count' , $qi->get( 'failed_attempt_count' ) + 1 );
			$qi->set( 'last_attempt_timestamp', $this->makeTimestamp() );
			$qi->set( 'not_before_timestamp', $this->determineNextAttempt($qi->get('event_type'), $qi->get('failed_attempt_count') ) );
			$qi->set( 'last_error_msg', $error_msg);
			$qi->save();
		}
	}
	
	function markAsHandled($item_id) {
		$qi = owa_coreAPI::entityFactory('base.queue_item');
		$qi->load($item_id);
		$inserted_timestamp = $qi->get('insertion_timestamp');
		if ($inserted_timestamp) {
			$qi->set( 'status', 'handled' );
			$qi->set( 'handled_timestamp', $this->makeTimestamp() );
			$qi->save();
		}
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
				$qi = owa_coreAPI::entityFactory( 'base.queue_item' );
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
	
	function processQueue() {
		
		$more = true;
		
		while( $more ) {
		
			$items = $this->getNextItems();
			
			if ( $items ) {
			
				foreach ( $items as $item ) {
					owa_coreAPI::debug('About to dispatch queue item id: ' . $item->get( 'id' ) );			
					$event = unserialize( $item->get('event') );
					$dispatch = owa_coreAPI::getEventDispatch();
					$ret = $dispatch->notify( $event );
					owa_coreAPI::debug($ret);
					
					$id = $item->get( 'id' );
					if ( $ret === OWA_EHS_EVENT_HANDLED ) {
						$this->markAsHandled( $id );
						owa_coreAPI::debug("EHS: marked item ($id) as handled.");
					} else {
						$this->markAsFailed( $id );
						owa_coreAPI::debug("EHS: marked item ($id) as failed.");
					}	
					
				}
				
			} else {
				$more = false;
			}
		}
	}
}

?>