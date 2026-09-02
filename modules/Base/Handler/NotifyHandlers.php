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
 * Click Event Handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */
class NotifyHandlers extends \OWA\Core\Observer {

    /**
     * Notify Handler
     *
     * @access     public
     * @param     object $event
     */
    function notify( $event ) {
        
        if ( $event->getSiteId() ) {

            /*
             * Was tested in Module::_registerEventHandlers, which runs before
             * any event exists and so could only ever read the install-wide
             * value. Both settings are per-Profile now, so the decision has
             * moved to the first point that knows which Profile this is.
             */
            $siteId = $event->getSiteId();

            $announce = \OWA\Core\CoreAPI::getSetting(
                'base', 'announce_visitors', 'profile', $siteId );

            $address = \OWA\Core\CoreAPI::getSetting(
                'base', 'notice_email', 'profile', $siteId );

            if ( ! $announce || ! $address ) {

                \OWA\Core\CoreAPI::debug(
                    'New session notify skipped: announcements are off for site_id ' . $siteId );

                return OWA_EHS_EVENT_HANDLED;
            }

            $s = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
            
            $s->load( $s->generateId( $event->getSiteId() ) );

            if ( $s->wasPersisted() ) {

                $ret = \OWA\Core\CoreAPI::performAction( 'base.notifyNewSession', array( 'site' => $s, 'event' => $event ) );

                return OWA_EHS_EVENT_HANDLED;

            } else {

                // No site entity exists for this event's site_id, so there is
                // no one to send the new-session announcement to. This is a
                // permanent condition (an unknown/unregistered site never
                // becomes registered by retrying), so treat it as handled --
                // returning FAILED would re-queue the event onto the processing
                // queue for a retry that can never succeed, piling up
                // undeliverable poison-pill rows in owa_queue_item.
                \OWA\Core\CoreAPI::debug( 'New session notify handled with no action: no persisted site found for site_id: ' . $event->getSiteId() );

                return OWA_EHS_EVENT_HANDLED;
            }
            
        } else {
            
            return OWA_EHS_EVENT_HANDLED;
        }
    }
}

?>