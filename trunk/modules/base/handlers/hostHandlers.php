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
require_once(OWA_DIR.'owa_lib.php');

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
	 * Constructor
	 *
	 * @param 	string $priority
	 * @param 	array $conf
	 * 
	 */
    function owa_hostHandlers() {
        
		return owa_hostHandlers::__construct();
    }
    
    function __construct() {
    	
    	return parent::__construct();
    }
	
    /**
     * Notify Event Handler
     *
     * @param 	unknown_type $event
     * @access 	public
     */
    function notify($event) {
		
    	$h = owa_coreAPI::entityFactory('base.host');
		
		$h->getByPk('id', owa_lib::setStringGuid($event->get('host')));
		$id = $h->get('id'); 
		
		if (!$id) {
			
			$h->setProperties($event->getProperties());
			
			$h->set('id', owa_lib::setStringGuid($event->get('host'))); 
			
			// makes the geo-location object from the service specified in the config
			$path = owa_coreAPI::getSetting('base', 'plugin_dir')."location/";
			$class = owa_coreAPI::getSetting('base', 'geolocation_service');
			$file = $class.'.php';
			$ret = require_once($path.$file);
			//$location = new owa_hostip;
			
			$location = owa_lib::factory($path, 'owa_', $class);
			
			// lookup
			$location->get_location($event->get('ip_address'));
			
			//set properties of the session
			$h->set('country', $location->country);
			$h->set('city', $location->city);
			$h->set('latitude', $location->latitude);
			$h->set('longitude', $location->longitude);
			
			$h->create();
			
		} else {
		
			owa_coreAPI::debug('Not Logging. Host already exists');
			
		}	
    }
}

?>