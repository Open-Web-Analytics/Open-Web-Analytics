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
	require_once(OWA_DIR.'owa_observer.php');
}	


/**
 * Search Term Handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */

class owa_searchTermHandlers extends owa_observer {
    
    /**
     * Notify Event Handler
     *
     * @param 	unknown_type $event
     * @access 	public
     */
    function notify($event) {
		
		$terms = trim(strtolower($event->get('search_terms')));
		
		if ($terms) {
		
    		$st = owa_coreAPI::entityFactory('base.search_term_dim');
			$st_id = owa_lib::setStringGuid($terms);
			$st->getByPk('id', $st_id);
			$id = $st->get('id'); 
		
			if (!$id) {
			
				$st->set('id', $st_id); 
				$st->set('terms', $terms);
				$ret = str_replace("","",$terms,$count);
				$st->set('term_count', $count);
				$ret = $st->create();
				
				if ( $ret ) {
					return OWA_EHS_EVENT_HANDLED;
				} else {
					return OWA_EHS_EVENT_FAILED;
				}
								
			} else {
		
				owa_coreAPI::debug('Not Logging. Search term already exists.');
				return OWA_EHS_EVENT_HANDLED;
			}
		} else {
			return OWA_EHS_EVENT_HANDLED;
		}
			
    }
}

?>