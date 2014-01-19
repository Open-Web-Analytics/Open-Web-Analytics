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
 * Host Event handlers
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_hostHandlers extends owa_observer {
    	
    /**
     * Notify Event Handler
     *
     * @param 	unknown_type $event
     * @access 	public
     */
    function notify( $event ) {
		
		if ( ! $event->get( 'host_id' ) ) {
		
			owa_coreAPI::notice('Not persisting host dimension. Host id missing from event.');
		
			return OWA_EHS_EVENT_HANDLED;
		}
		
    	$h = owa_coreAPI::entityFactory('base.host');
		
		$h->getByPk( 'id', $event->get( 'host_id' ) );
		
		$id = $h->get('id'); 
		
		if (!$id) {
			
			$h->setProperties( $event->getProperties() );
			$h->set( 'id', $event->get( 'host_id' ) ); 
			$ret = $h->create();
			
			if ( $ret ) {
				return OWA_EHS_EVENT_HANDLED;
			} else {
				return OWA_EHS_EVENT_FAILED;
			}
			
		} else {
		
			owa_coreAPI::debug('Not Persisting. Host already exists.');
			return OWA_EHS_EVENT_HANDLED;
		}	
    }
}

?>