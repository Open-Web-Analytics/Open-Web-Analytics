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
 * Action Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.3.0
 */

class owa_actionHandler extends owa_observer {
    
    /**
     * Notify Event Handler
     *
     * @param 	unknown_type $event
     * @access 	public
     */
    function notify($event) {
		
    	$a = owa_coreAPI::entityFactory('base.action_fact');
		
		$a->load( $event->get( 'guid' ) );
		
		if ( ! $a->wasPersisted() ) {
			
			$a->set('id', $event->get( 'guid' ) );
			$a->set('visitor_id', $event->get('visitor_id'));
			$a->set( 'session_id', $event->get( 'session_id' ) );
			$a->set('site_id', $event->get('site_id'));
			$a->set('document_id', $a->generateId($event->get('page_url')));
			$a->set('ua_id', $a->generateId($event->get('HTTP_USER_AGENT')));
			$a->set('host_id', $a->generateId($event->get('full_host')));
			$a->set('os_id', $a->generateId($event->get('os')));
			$a->set('timestamp', $event->get('timestamp'));
			$a->set('yyyymmdd', $event->get('yyyymmdd'));
			$a->set('action_name', strtolower(trim($event->get('action_name'))));
			$a->set('action_group', strtolower(trim($event->get('action_group'))));
			$a->set('action_label', strtolower(trim($event->get('action_label'))));
			$a->set('numeric_value', $event->get('numeric_value') * 1);
			
			$ret = $a->create();
			
			if ( $ret ) {
				return OWA_EHS_EVENT_HANDLED;
			} else {
				return OWA_EHS_EVENT_FAILED;
			}
	    }
	}
}

?>