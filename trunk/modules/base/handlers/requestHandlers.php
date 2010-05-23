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
require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'ini_db.php');

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
		$r->set('host_id', owa_lib::setStringGuid($event->get('host')));
		
		if ($event->get('external_referer')) {
			$qt = $this->extractSearchTerms($event->get('HTTP_REFERER'));
			
			if ($qt) {
				$event->set('query_terms', $qt);
			}
		}
		
		$result = $r->create();
		
		if ($result == true) {
			$event->setEventType($event->getEventType().'_logged');
			$eq = owa_coreAPI::getEventDispatch();
			$eq->notify($event);
		}
		
	}
	
	
	/**
	 * Parses query terms from referer
	 *
	 * @param string $referer
	 * @return string
	 * @access private
	 */
	function extractSearchTerms($referer) {
	
		/*	Look for query_terms */
		$db = new ini_db(owa_coreAPI::getSetting('base', 'query_strings.ini'));
		
		$match = $db->match($referer);
		
		if (!empty($match[1])) {
		
			return trim(strtolower(urldecode($match[1])));
		
		}
	}
}

?>