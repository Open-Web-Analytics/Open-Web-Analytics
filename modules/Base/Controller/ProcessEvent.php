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
 * Generic Event Processor Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class ProcessEvent extends \OWA\Core\Controller {

    var $event;
    var $eq;

    function __construct($params) {

        if (array_key_exists('event', $params) && !empty($params['event'])) {

            $this->event = $params['event'];

        } else {
            \OWA\Core\CoreAPI::debug("No event object was passed to controller.");
            $this->event = \OWA\Core\CoreAPI::supportClassFactory('base', 'event');
        }

        $this->eq = \OWA\Core\CoreAPI::getEventDispatch();

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

        // TODO: move this all into the coreAPI::logEvent method. We really don't need the overhead of a controller for this.

        $teh = \OWA\Core\CoreAPI::getInstance( 'owa_trackingEventHelpers', OWA_BASE_CLASS_DIR.'trackingEventHelpers.php');

        $s = \OWA\Core\CoreAPI::serviceSingleton();

        // STAGE 1 - set environmental properties from SERVER
        // now happens in coreAPI::logEvent

        // STAGE 2 - process incomming properties

        $properties = $s->getMap( 'tracking_properties_regular' );

        // there is no global input sanitization on tracking requests
        // because each module needs to register tracking properties and
        // their data types. Therefor we need to sanitize unregistered input
        // here before we pass it along to any handlers.

        // get a list of properties that we do not know the data type of
        /*
         * Everything the event carries that NO module has registered.
         *
         * Diffed against every registered map, not just the regular one. It
         * used to diff against `regular` alone, which left all 39 derived
         * properties and the environmental ones in this set -- so a value a
         * client sent for a SERVER-COMPUTED property was captured here, and the
         * re-apply at the end of this method put it back on top of the value
         * the derivation had just computed.
         *
         * That is how a tracking request carrying owa_is_browser=ludhiana ended
         * up writing 'ludhiana' into a boolean column: the derivation ran
         * correctly and was then undone. Environmental properties -- ip_address,
         * timestamp -- were overwritable the same way, which is the more
         * serious half.
         */
        $protected = $properties + $teh->serverOwnedProperties();

        $unsanitized_properties = array_diff_key( $this->event->getProperties(), $protected );

        // santize them genericly. we will apply them back to the event later
        $sanitized_properties = \OWA\Module\Base\Classes\Sanitize::cleanInput( $unsanitized_properties, array('remove_html' => true) );
        //owa_coreAPI::debug( print_r($sanitized_properties, true ) );

        // translate custom var properties
        $teh->translateCustomVariables( $this->event );

        $teh->setTrackerProperties( $this->event, $properties );

        // STAGE 3 - derived properties

        $derived_properties = $s->getMap( 'tracking_properties_derived' );

        /*
         * The cv{n}_name / cv{n}_value pairs are DERIVED -- the server makes
         * them by splitting the cv{n} slot the tracker sent. They used to be
         * merged into the regular map, which made them settable from the wire:
         * a request posting owa_cv1_name survived the filter and was then
         * re-applied over the split result by the sanitized-properties step
         * below.
         */
        $derived_properties = $teh->addCustomVariableProperties( $derived_properties );
        $teh->setTrackerProperties( $this->event, $derived_properties );

        // re-apply sanitized properties to event.
        $this->event->setProperties( $sanitized_properties );
    }

    function post() {
        
        return $this->addToEventQueue();
    }

    function addToEventQueue() {
        
        if ( ! $this->isTrackingEvent() ) {
            
            \OWA\Core\CoreAPI::debug("Not dispatching event. This is not a valid tracking event type.");
            return;
        }
        
        if ( ! $this->event->get( 'do_not_log' ) ) {

            //filter event
            $this->event = $this->eq->filter( 'post_processed_tracking_event', $this->event );

            \OWA\Core\CoreAPI::debug( 'Dispatching ' . $this->event->getEventType() . ' event with properties: ' . print_r($this->event->getProperties(), true ) );
            $this->eq->notify( $this->event );

        } else {

            \OWA\Core\CoreAPI::debug("Not dispatching event due to 'do not log' flag being set.");
        }
    }
    
    function isTrackingEvent() {
        
        if ( in_array( $this->event->getEventType(), \OWA\Core\CoreAPI::getSetting('base', 'tracking_event_types' ) ) ) {
            
            return true;
        }
    }
}

?>