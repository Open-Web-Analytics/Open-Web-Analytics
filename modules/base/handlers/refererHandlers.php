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

if(!class_exists('owa_observer')) {
	require_once(OWA_BASE_DIR.'owa_observer.php');
}	

if (!class_exists('owa_http')) {
	require_once(OWA_BASE_DIR.'/owa_httpRequest.php');
}

/**
 * OWA Referer Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_refererHandlers extends owa_observer {
    	
    /**
     * Notify Event Handler
     *
     * @param 	unknown_type $event
     * @access 	public
     */
    function notify($event) {
		
		// if there is no session referer then return
		if ( ! $event->get('referer_id') ) {
			return OWA_EHS_EVENT_HANDLED;
		}
		
    	// Make entity
		$r = owa_coreAPI::entityFactory('base.referer');
		
		$r->load( $event->get( 'referer_id' ) );
		
		if ( ! $r->wasPersisted() ) {
			
			$r->set( 'id', $event->get( 'referer_id' ) );
			
			// set referer url
			$r->set('url', $event->get('session_referer'));
				
			// Set site
			$url = owa_lib::parse_url( $event->get( 'session_referer' ) );
			
			$r->set( 'site', $url['host'] );
			
			$medium = $event->get('medium');
			
			if ( $medium === 'organic-search' ) {
			
				$r->set('is_searchengine', true);
			}
				
			// set title. this will be updated later by the crawler.
			$r->set('page_title', '(not set)');
			
			// Crawl and analyze refering page
			if (owa_coreAPI::getSetting( 'base', 'fetch_refering_page_info') && $medium != 'organic-search' ) {
				//owa_coreAPI::debug('hello from logReferer');
				$crawler = new owa_http;
				//$crawler->fetch($this->params['HTTP_REFERER']);
				$res = $crawler->getRequest($event->get('session_referer'));
				owa_coreAPI::debug('http request response: '.print_r($res, true));
				//Extract Title
				
				$title = trim($crawler->extract_title());
				
				if ($title) {
					
					$r->set('page_title', owa_lib::utf8Encode( $title ) );	
				}	
				
				$se = $r->get('is_searchengine');
				//Extract anchortext and page snippet but not if it's a search engine...
				if ($se != true) {
				
					$snippet = $crawler->extract_anchor_snippet($event->get('page_url'));
					
					if ($snippet) {
						if (function_exists('iconv')) {
							$snippet = iconv('UTF-8','UTF-8//TRANSLIT',$snippet);
						}
						$r->set('snippet', $snippet);
					}
					
					$anchortext = $crawler->anchor_info['anchor_text'];
					
					if ($anchortext) {
				
						$r->set('refering_anchortext', owa_lib::utf8Encode( $anchortext ) );
					}
				}	
			}
			
			// Persist to database
			$ret = $r->create();
			
			if ( $ret ) {
				return OWA_EHS_EVENT_HANDLED;
			} else {
				return OWA_EHS_EVENT_FAILED;
			}
			
		} else {
			owa_coreAPI::debug('Not Persisting. Referrer already exists.');
			return OWA_EHS_EVENT_HANDLED;
		}
    }
}

?>