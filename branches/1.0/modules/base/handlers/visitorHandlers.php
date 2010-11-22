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
		
    	switch ($event->get('is_new_visitor')) {
    		
    		case true:
    			return $this->logVisitor($event);
    		
    		case false:
    			return $this->logVisitorUpdate($event);
    			break;
    	}
    }
    
    function logVisitor($event) {
    	
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
			$v->set('num_prior_sessions', $event->get('num_prior_sessions'));
			$ret = $v->create();
			
			if ( $ret ) {
				return OWA_EHS_EVENT_HANDLED;
			} else {
				return OWA_EHS_EVENT_FAILED;
			}
			
		} else {
			owa_coreAPI::debug("Not persisting. Visitor already exists.");
			return OWA_EHS_EVENT_HANDLED;
		}
    }
    
    function logVisitorUpdate($event) {
    	
    	$v = owa_coreAPI::entityFactory('base.visitor');

		$v->load( $event->get('visitor_id' ) );
		
		if ( $v->wasPersisted() ) {
			if ( $event->get( 'user_name' ) ) {
				$v->set( 'user_name', $event->get( 'user_name' ) );
			}
			
			if ($event->get('user_email')) {
				$v->set('user_email', $event->get('user_email'));
			}
			$v->set('last_session_id', $event->get('session_id'));
			$v->set('last_session_year', $event->get('year'));
			$v->set('last_session_month', $event->get('month'));
			$v->set('last_session_day', $event->get('day'));
			$v->set('last_session_dayofyear', $event->get('dayofyear'));
			$v->set('num_prior_sessions', $event->get('num_prior_sessions'));	
			$ret = $v->update();
			
			if ( $ret ) {
				return OWA_EHS_EVENT_HANDLED;
			} else {
				return OWA_EHS_EVENT_FAILED;
			}
			
		} else {
			owa_coreAPI::debug("Not updating visitor. Visitor does not exists, adding it now.");
			return $this->logVisitor($event);
			//return OWA_EHS_EVENT_FAILED;
		}
    }
}

?>