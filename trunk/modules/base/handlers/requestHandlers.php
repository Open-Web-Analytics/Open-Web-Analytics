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
	 * Constructor
	 *
	 * @param 	string $priority
	 * @param 	array $conf
	 * @access 	public
	 * @return 	Log_observer_request_logger
	 */
    function __construct() {
		
		return parent::__construct();
    }

    /**
     * Notify Handler
     *
     * @access 	public
     * @param 	object $event
     */
    function notify($event) {
    
    	$r = owa_coreAPI::entityFactory('base.request');
		
		//print_r($r);
	
		$r->setProperties($event->getProperties());
	
		// Set Primary Key
		$r->set('id', $event->get('guid'));
		
		// Make ua id
		$r->set('ua_id', owa_lib::setStringGuid($event->get('HTTP_USER_AGENT')));
	
		// Make OS id
		$r->set('os_id', owa_lib::setStringGuid($event->get('os')));
	
		// Make document id	
		$r->set('document_id', owa_lib::setStringGuid($event->get('page_url')));
		
		// Make prior document id	
		$r->set('prior_document_id', owa_lib::setStringGuid($event->get('prior_page')));
		
		// Generate Referer id
		$r->set('referer_id', owa_lib::setStringGuid($event->get('HTTP_REFERER')));
		
		// Generate Host id
		$r->set('host_id', owa_lib::setStringGuid($event->get('full_host')));
		
		$result = $r->create();
		
		
		if ($result == true) {
			
			
			//$nevent->setEventType($event->getEventType().'_logged');
			$eq = owa_coreAPI::getEventDispatch();
			$nevent = $eq->makeEvent($event->getEventType().'_logged');
			$nevent->setProperties($event->getProperties());
			$eq->notify($nevent);
		}
		
	}
}

?>