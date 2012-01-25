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


/**
 * Request Event Handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_requestHandlers extends owa_observer {

    /**
     * Notify Handler
     *
     * @access 	public
     * @param 	object $event
     */
    function notify($event) {
    
    	$r = owa_coreAPI::entityFactory('base.request');
    	
    	$r->load( $event->get('guid') );
    	
    	if ( ! $r->wasPersisted() ) {
    	
			$r->setProperties($event->getProperties());
		
			// Set Primary Key
			$r->set('id', $event->get('guid'));
			
			// Make prior document id	
			$r->set('prior_document_id', owa_lib::setStringGuid($event->get('prior_page')));
			
			// Generate Host id
			$r->set('num_prior_sessions', $event->get('num_prior_sessions'));
							
			$result = $r->create();
			
			if ($result == true) {
			
				$eq = owa_coreAPI::getEventDispatch();
				$nevent = $eq->makeEvent($event->getEventType().'_logged');
				$nevent->setProperties($event->getProperties());
				$eq->asyncNotify($nevent);
				return OWA_EHS_EVENT_HANDLED;
			} else {
				return OWA_EHS_EVENT_FAILED;
			}
		} else {
			owa_coreAPI::debug('Not persisting. Request already exists.');
			return OWA_EHS_EVENT_HANDLED;
		}
	}
}

?>