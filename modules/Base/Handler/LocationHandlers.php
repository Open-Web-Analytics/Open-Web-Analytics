<?php
namespace OWA\Module\Base\Handler;

use OWA\Module\Base\Classes\Geolocation;


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
 * Location Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */

class LocationHandlers extends \OWA\Core\Observer {
        
    /**
     * Notify Event Handler
     *
     * @param     mixed $event
     * @access     public
     */
    function notify($event) {
        
        if ( $event->get( 'location_id' ) || $event->get( 'ip_address' ) ) {

            $h = \OWA\Core\CoreAPI::entityFactory('base.location_dim');
            
            /*
             * The dimension id is derived in exactly one place --
             * Geolocation::idFor() -- and this handler and the fact table's
             * generateLocationId() callback both go through it.
             *
             * They used to derive it separately and disagree: this handler
             * keyed on country.city, the fact callback on country.state.city.
             * So for any location with a state, the row created here carried an
             * id no fact row ever pointed at, and the row the facts did point at
             * was created by nothing. Every geo report depended on the two
             * hashes happening to coincide.
             */

            // look for location id on the event. This happens when
            // another event has already created it.
            if ( $event->get( 'location_id' ) ) {
                
                $location_id = $event->get('location_id');
            // else look to see if he event has the minimal geo properties
            // if it does then assume that geo properties are set.
            } elseif ( $event->get('country') ) {

                $location_id = Geolocation::idFor(
                    $event->get('country'), $event->get('state'), $event->get('city') );
            // load the geo properties from the geo service.
            } else {
                $location = \OWA\Core\CoreAPI::getGeolocationFromIpAddress($event->get('ip_address'));
                \OWA\Core\CoreAPI::debug('geolocation: ' .print_r($location, true));
                //set properties of the session
                $event->set('country', $location->getCountry());
                $event->set('city', $location->getCity());
                $event->set('latitude', $location->getLatitude());
                $event->set('longitude', $location->getLongitude());
                $event->set('country_code', $location->getCountryCode());
                $event->set('state', $location->getState());

                /*
                 * A lookup that resolved nothing still gets a row, and that row
                 * still holds NULL.
                 *
                 * The row has to exist because the fact table's location_id is
                 * a plain inner join in every geo report: a fact pointing at an
                 * id no row carries is not reported as "(not set)", it is not
                 * reported at all. What must not exist is the old '(not set)'
                 * STRING in the columns -- that is what made country != 'US'
                 * silently exclude unresolved rows, and it is what this branch
                 * no longer writes. The columns are left unset, so they store
                 * NULL, and ResultSetManager labels them at render time.
                 */
                $location_id = Geolocation::idFor(
                    $event->get('country'), $event->get('state'), $event->get('city') );
            }
            
            // look up the county code if it's missing
            if ( ! $event->get('country_code') && $event->get('country') ) {
                $event->set( 'country_code', $this->lookupCountryCodeFromName( $event->get('country') ) );
            }
            
            $h->getByPk('id', $location_id );
            $id = $h->get('id');
            
            if (!$id) {
                
                $location = \OWA\Core\CoreAPI::getGeolocationFromIpAddress($event->get('ip_address'));
                \OWA\Core\CoreAPI::debug('geolocation: ' .print_r($location, true));
                
                //set properties of the session
                $h->set('country', $event->get('country'));
                $h->set('city', $event->get('city'));
                $h->set('latitude', $event->get('latitude'));
                $h->set('longitude', $event->get('longitude'));
                $h->set('country_code', $event->get('country_code'));
                $h->set('state', $event->get('state'));
                $h->set('id', $location_id);
                $ret = $h->create();
                
                if ( $ret ) {
                    return OWA_EHS_EVENT_HANDLED;
                } else {
                    return OWA_EHS_EVENT_FAILED;
                }
                
            } else {
            
                \OWA\Core\CoreAPI::debug('Not Logging. Location already exists');
                return OWA_EHS_EVENT_HANDLED;
            }
        } else {
            
            \OWA\Core\CoreAPI::notice('Not persisting location dimension. Location id or ip address missing from event.');
            
            return OWA_EHS_EVENT_HANDLED;
        }
    }
    
    function lookupCountryCodeFromName($name) {
        include_once(OWA_DIR.'conf/countryNames2Codes.php');
        $name = trim(strtolower($name));
        if (array_key_exists($name, $countryName2Code)) {
            return $countryName2Code[$name];
        }
        return false;
    }
}

?>