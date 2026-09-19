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
 * OWA Referer Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class RefererHandlers extends \OWA\Core\Observer {

    /**
     * Notify Event Handler
     *
     * @param     mixed $event
     * @access     public
     */
    function notify($event) {

        /*
         * Null means this event has no referrer at all, which is not the same
         * as one we could not resolve: base.referer declares its absence
         * NOT_APPLICABLE, because direct traffic has no referring site and a
         * referring-sites report should exclude it rather than invent a bucket.
         *
         * This guard used to read an id off the event, so it would have gone on
         * returning early -- silently, writing no referer rows at all -- once
         * the pipeline stopped putting one there.
         */
        $referer_id = \OWA\Module\Base\Entity\Referer::deriveId( $event->getProperties() );

        if ( $referer_id === null ) {

            return OWA_EHS_EVENT_HANDLED;
        }

        // Make entity
        $r = \OWA\Core\CoreAPI::entityFactory('base.referer');

        $r->load( $referer_id );

        $r->detectIdCollision( 'url', $event->get( 'session_referer' ) );
        
        $medium = $event->get('medium');

        if ( ! $r->wasPersisted() ) {

            $r->set( 'id', $referer_id );

            // set referer url
            $r->set('url', $event->get('session_referer'));

            // Set site
            $url = \OWA\Core\Lib::parse_url( $event->get( 'session_referer' ) );

            $r->set( 'site', $url['host'] );

            if ( $medium === 'organic-search' ) {

                $r->set('is_searchengine', true);
            }

            /*
             * No title, and nothing will supply one later.
             *
             * This line used to read "this will be updated later by the
             * crawler". There is no crawler: fetching the referring page was
             * removed because the URL arrives on the anonymous tracking beacon,
             * so any visitor could make the server issue an HTTP GET to an
             * address of their choosing. RefererCrawlRemovedTest keeps it gone.
             *
             * So the placeholder was permanent, and 22,802 of demo's 25,457
             * referer rows carry it. Leaving the column unset is the honest
             * record; referralPageTitle renders as "(not set)" at display time.
             */

            // Persist to database
            $ret = $r->create();

            if ( $ret ) {
                return OWA_EHS_EVENT_HANDLED;
            } else {
                return OWA_EHS_EVENT_FAILED;
            }

        } else {
	        
	        // check and update medium if it's new
	        // @todo make this check for a "allow_slowly_changing_dimensions" setting flag
	        
	        if ( \OWA\Core\CoreAPI::getSetting('base', 'allow_slowly_changing_dimensions') ) {
		        
		        if ( $medium != $r->get( 'medium' ) ) {
			        
			        $r->set( 'medium', $medium );
			        
			        if ( $medium === 'organic-search' ) {

		                $r->set('is_searchengine', true);
		            }
		            
			        $r->save();
			        
			        \OWA\Core\CoreAPI::debug("Updating Referrer medium to be: $medium");
		        }
			}
			
            \OWA\Core\CoreAPI::debug('Not Persisting. Referrer already exists.');
            return OWA_EHS_EVENT_HANDLED;
        }
    }
}

?>