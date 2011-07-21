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
 * OWA Visitor Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_visitorHandlers extends owa_observer {
    	
    /**
     * Notify Event Handler
     *
     * @param 	unknown_type $event
     * @access 	public
     */
    function notify($event) {
			
    	$v = owa_coreAPI::entityFactory('base.visitor');
    	
    	$v->load( $event->get( 'visitor_id' ) );
    	
    	if ( ! $v->wasPersisted() ) {
    		
			$v->setProperties($event->getProperties());
		
			// Set Primary Key
			$v->set( 'id', $event->get('visitor_id') );
			
			$v->set('user_name', $event->get('user_name'));
			$v->set('user_email', $event->get('user_email'));
			$v->set('first_session_id', $event->get('session_id'));
			$v->set('first_session_year', $event->get('year'));
			$v->set('first_session_month', $event->get('month'));
			$v->set('first_session_day', $event->get('day'));
			$v->set('first_session_dayofyear', $event->get('dayofyear'));
			$v->set('first_session_timestamp', $event->get('timestamp'));
			
			$ret = $v->create();
			
			if ( $ret ) {
				return OWA_EHS_EVENT_HANDLED;
			} else {
				return OWA_EHS_EVENT_FAILED;
			}
			
		} else {
			
			if ( owa_coreAPI::getSetting('base', 'update_visitor_attributes') ) {
			
				$update = false;
				
				// check for different user_name
				$user_name = $event->get( 'user_name' );
				$old_user_name = $v->get( 'user_name' );
				if ( $user_name && $user_name != $old_user_name ) {
					$v->set( 'user_name', $event->get( 'user_name' ) );
					$update = true;
				}
				
				// check for different email_address
				$user_email = $event->get( 'user_email' );
				$old_user_email = $v->get( 'user_email' );
				if ( $user_email && $user_email != $old_user_email ) {
					$v->set( 'user_email', $event->get( 'user_email' ) );
					$update = true;
				}
				
				if ( $update ) {
				
					owa_coreAPI::debug("Persisting. Visitor requires updating.");
				
					$ret = $v->update();
					
					if ( $ret ) {
						return OWA_EHS_EVENT_HANDLED;
					} else {
						return OWA_EHS_EVENT_FAILED;
					}
				}
			}
			
			owa_coreAPI::debug("Not persisting. Visitor already exists and no updates are needed.");
			return OWA_EHS_EVENT_HANDLED;
					
		}
    }
}

?>