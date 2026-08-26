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

        // if there is no session referer then return
        if ( ! $event->get('referer_id') ) {
            return OWA_EHS_EVENT_HANDLED;
        }

        // Make entity
        $r = \OWA\Core\CoreAPI::entityFactory('base.referer');

        $r->load( $event->get( 'referer_id' ) );

        $r->detectIdCollision( 'url', $event->get( 'session_referer' ) );
        
        $medium = $event->get('medium');

        if ( ! $r->wasPersisted() ) {

            $r->set( 'id', $event->get( 'referer_id' ) );

            // set referer url
            $r->set('url', $event->get('session_referer'));

            // Set site
            $url = \OWA\Core\Lib::parse_url( $event->get( 'session_referer' ) );

            $r->set( 'site', $url['host'] );

            if ( $medium === 'organic-search' ) {

                $r->set('is_searchengine', true);
            }

            // set title. this will be updated later by the crawler.
            $r->set('page_title', '(not set)');

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