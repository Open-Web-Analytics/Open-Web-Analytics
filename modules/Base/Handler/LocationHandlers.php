<?php
namespace OWA\Module\Base\Handler;



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
        
        if ( $event->get( 'country' ) || $event->get( 'city' ) || $event->get( 'ip_address' ) ) {

            $h = \OWA\Core\CoreAPI::entityFactory('base.location_dim');

            /*
             * One derivation, from content, owned by the dimension.
             *
             * Three branches stood here. The first read location_id straight off
             * the event and reused it -- hashing nothing, but trusting a key the
             * pipeline derived, which is how a handler could write a row keyed on
             * content the event no longer carried. The third re-resolved the IP
             * and wrote country/city/state back onto the event, then read them
             * off it again forty lines later to fill the row: the event used as a
             * scratch variable, at the cost of a geolocation lookup whose result
             * was discarded.
             *
             * Both were already redundant. resolveCountry/resolveCity/
             * resolveState run in the property pipeline before any handler sees
             * the event, so the content is present; and the geolocation service
             * caches per process, so re-resolving returned the same answer it had
             * already given. Removing them also removes the only place a handler
             * mutated a resolution output, which matters now that facts derive
             * their keys at write time -- a handler changing country between two
             * fact writes would give one event two different location_ids.
             *
             * base.location_dim declares absence UNKNOWN, so an address that
             * resolved to nothing still gets the shared row rather than null. It
             * has to: dimension joins are INNER, and a fact pointing at no row
             * leaves every geo report entirely rather than grouping under
             * "(not set)".
             */
            $location_id = \OWA\Module\Base\Entity\LocationDim::deriveId( $event->getProperties() );

            // look up the county code if it's missing
            if ( ! $event->get('country_code') && $event->get('country') ) {
                $event->set( 'country_code', $this->lookupCountryCodeFromName( $event->get('country') ) );
            }
            
            $h->getByPk('id', $location_id );
            $id = $h->get('id');
            
            if (!$id) {
                
                /*
                 * No lookup here. There was one, assigned to $location and then
                 * never read -- the row was filled from the event, which the
                 * property pipeline had already resolved. A second call cost a
                 * MaxMind read (or would have, but for the per-process cache)
                 * and its result was discarded.
                 */
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