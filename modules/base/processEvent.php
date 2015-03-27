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

require_once(OWA_BASE_DIR.'/owa_controller.php');

/**
 * Generic Event Processor Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_processEventController extends owa_controller {
	
	var $event;
	var $eq;
	
	function __construct($params) {
	
		if (array_key_exists('event', $params) && !empty($params['event'])) {
			
			$this->event = $params['event'];
				
		} else {
			owa_coreAPI::debug("No event object was passed to controller.");
			$this->event = owa_coreAPI::supportClassFactory('base', 'event');
		}
				
		$this->eq = owa_coreAPI::getEventDispatch();
		
		return parent::__construct($params);
	
	}
	
	/**
	 * Main Control Logic
	 *
	 * @return unknown
	 */
	function action() {
			
		return;
		
	}
	
	/**
	 * Must be called before all other event property setting functions
	 */
	function pre() {
		
		$teh = owa_coreAPI::getInstance( 'owa_trackingEventHelpers', OWA_BASE_CLASS_DIR.'trackingEventHelpers.php');
		
		$s = owa_coreAPI::serviceSingleton();
		
		// STAGE 1 - set environmental properties from SERVER
		// now happens in coreAPI::logEvent
		
		// STAGE 2 - process incomming properties
		
		$properties = $s->getMap( 'tracking_properties_regular' );
		
		// add custom var properties
		$properties = $teh->addCustomVariableProperties( $properties );
		// translate custom var properties
		$teh->translateCustomVariables( $this->event );
		
		$teh->setTrackerProperties( $this->event, $properties );	
		
		// STAGE 3 - derived properties
		
		$derived_properties = $s->getMap( 'tracking_properties_derived' );
		$teh->setTrackerProperties( $this->event, $derived_properties );
	}
	
	function post() {
			
		return $this->addToEventQueue();
	}
	
	function addToEventQueue() {
	
		if ( ! $this->event->get( 'do_not_log' ) ) {
			
			//filter event
			$this->event = $this->eq->filter( 'post_processed_tracking_event', $this->event );
		
			owa_coreAPI::debug( 'Dispatching ' . $this->event->getEventType() . ' event with properties: ' . print_r($this->event->getProperties(), true ) );
			$this->eq->notify( $this->event );
		
		} else {
			
			owa_coreAPI::debug("Not dispatching event due to 'do not log' flag being set.");
		}
	}	
}

?>