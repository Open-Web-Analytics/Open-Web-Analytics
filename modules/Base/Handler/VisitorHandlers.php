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
 * OWA Visitor Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class VisitorHandlers extends \OWA\Core\Observer {

    /**
     * Notify Event Handler
     *
     * @param     mixed $event
     * @access     public
     */
    function notify($event) {

        if ( $event->get( 'visitor_id' ) ) {

            $v = \OWA\Core\CoreAPI::entityFactory('base.visitor');

            $v->load( $event->get( 'visitor_id' ) );

            if ( ! $v->wasPersisted() ) {

                $v->setProperties($event->getProperties());

                // Set Primary Key
                $v->set( 'id', $event->get( 'visitor_id' ) );
                $v->set('first_session_id', $event->get('session_id'));
                $v->set('first_session_year', $event->get('year'));
                $v->set('first_session_month', $event->get('month'));
                $v->set('first_session_day', $event->get('day'));
                $v->set('first_session_dayofyear', $event->get('dayofyear'));
                $v->set('first_session_timestamp', $event->get('timestamp'));
                $v->set('first_session_yyyymmdd', $event->get('yyyymmdd'));

                /*
                 * Acquisition, written once and only here.
                 *
                 * These are already on the event: session_referer arrives on
                 * the wire and source/medium/campaign/ad/search_terms are
                 * resolved from it during the tracking-property pass, long
                 * before any handler runs. So the visitor's acquisition is the
                 * session values of the request that created it -- no lookup,
                 * no second event, and nothing new for the tracker to send.
                 *
                 * Set explicitly rather than left to setProperties() above,
                 * which matches on column name and would not connect `source`
                 * to `first_session_source`. The rename is deliberate: it says
                 * which session these describe, and keeps them distinct from
                 * the session-scoped columns of the same name on the fact row.
                 *
                 * Nothing writes them again. The else branch below is the
                 * returning visitor and leaves the row alone, which is what
                 * makes this first-touch rather than a sliding window.
                 */
                $v->set('first_session_source',       $event->get('source'));
                $v->set('first_session_medium',       $event->get('medium'));
                $v->set('first_session_campaign',     $event->get('campaign'));
                $v->set('first_session_ad',           $event->get('ad'));
                $v->set('first_session_search_terms', $event->get('search_terms'));

                $ret = $v->save();

                if ( $ret ) {
                    return OWA_EHS_EVENT_HANDLED;
                } else {
                    return OWA_EHS_EVENT_FAILED;
                }

            } else {

                \OWA\Core\CoreAPI::debug("Not updating... Visitor already exists.");
                return OWA_EHS_EVENT_HANDLED;
            }

        } else {

            \OWA\Core\CoreAPI::debug("No visitor_id part of event...");
            return OWA_EHS_EVENT_HANDLED;
        }
    }
}

?>