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

require_once OWA_BASE_CLASS_DIR.DIRECTORY_SEPARATOR.'event.php';
require_once OWA_BASE_DIR.DIRECTORY_SEPARATOR.'owa_lib.php';
require_once OWA_BASE_DIR.DIRECTORY_SEPARATOR.'ini_db.php';

/**
 * Concrete Page Request Event Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_requestEvent extends owa_event {
	
	/**
	 * First hit flag
	 * 
	 * Used to tell if this request was loaded from the first hit cookie. 
	 *
	 * @var boolean
	 */
	var $first_hit = false;
	
	/**
	 * Constructor
	 *
	 * @return owa_request
	 * @access public
	 */
	function owa_requestEvent() {
		
		//Call to Parent Constructor
		$this->owa_event();
	
		return;
	
	}
	
	/**
	 * Log page request to event queue
	 *
	 */
	function log() {
		
		if ($this->state == 'page_request' || $this->state == 'first_page_request'):
			if ($this->config['delay_first_hit'] == true):	
				if ($this->first_hit != true):
					// If not, then make sure that there is an inbound visitor_id
					if (empty($this->properties['inbound_visitor_id'])):
						// Log request properties to a cookie for processing by a second request and return
						$this->e->debug('Logging this request to first hit cookie.');
						return $this->log_first_hit();
					endif;
				endif;
			endif;
		endif;
		
		$this->e->debug('Logging '.'base.'.$this->state.' to event queue...');
		
		return $this->eq->log($this->properties, 'base.'.$this->state);
		
	}
	
	/**
	 * Load request properties from delayed first hit cookie.
	 *
	 * @param 	array $properties
	 * @access 	public
	 */
	function load_first_hit_properties($properties) {
		
		$this->properties['inbound_first_hit_properties'] = $properties;
         
        $array = explode("|||", $properties);

		foreach ($array as $key => $value):

			list($realkey, $realvalue) = split('=>', $value);
          	$this->properties[$realkey] = $realvalue;

        endforeach;
          
          //$this->e->debug('unserialized first it array: '.print_r($this->properties, true));

		
		// Mark the request to avoid logging it to the first hit cookie again
		$this->first_hit = true;
		
		// Delete first_hit Cookie
		setcookie($this->config['ns'].$this->config['first_hit_param'], '', time()-3600*24*365*30, "/", $this->config['cookie_domain']);
		
		return;
	}
	
	
	/**
	 * Log request properties of the first hit from a new visitor to a special cookie.
	 * 
	 * This is used to determine if the request is made by an actual browser instead 
	 * of a robot with spoofed or unknown user agent.
	 * 
	 * @access 	public
	 */
	function log_first_hit() {
		
		$values = owa_lib::implode_assoc('=>', '|||', $this->properties);
		setcookie($this->config['ns'].$this->config['first_hit_param'], $values, time()+3600*24*365*30, "/", $this->config['cookie_domain']);
		$this->e->debug('First hit cookie values: '.$values);
		return true;
	
	}
	
	/**
	 * Assigns visitor IDs
	 *
	 */
	function assign_visitor($inbound_visitor_id) {
		
		// is this new visitor?
	
		if (empty($inbound_visitor_id)):
			$this->set_new_visitor();
		else:
			$this->properties['visitor_id'] = $inbound_visitor_id;
			$this->properties['is_repeat_visitor'] = true;
		endif;
		
		return;
	}
	
	/**
	 * Make Session IDs
	 *
	 */
	function sessionize($inbound_session_id) {
		
			// check for inbound session id
			if (!empty($inbound_session_id)):
				 
				 if (!empty($this->properties['last_req'])):
							
					if ($this->time_since_lastreq < $this->config['session_length']):
						$this->properties['session_id'] = $inbound_session_id;		
						
					else:
					//prev session expired, because no hits in half hour.
						$this->create_new_session($this->properties['visitor_id']);
					endif;
				else:
				//session_id, but no last_req value. whats up with that?  who cares. just make new session.
					$this->create_new_session($this->properties['visitor_id']);
				endif;
			else:
			//no session yet. make one.
				$this->create_new_session($this->properties['visitor_id']);
			endif;
						
		return;
	}
	
	/**
	 * Creates new session id 
	 *
	 * @param 	integer $visitor_id
	 * @access 	public
	 */
	function create_new_session($visitor_id) {
	
		//generate new session ID 
	    $this->properties['session_id'] = $this->set_guid();
	
		//mark entry page flag on current request
		$this->properties['is_entry_page'] = true;
		
		//mark new session flag on current request
		$this->properties['is_new_session'] = true;
		
		//mark even state as first_page_request.
		$this->state = 'first_page_request';
		$this->properties['event_type'] = 'base.first_page_request';
		
		//Set the session cookie
        setcookie($this->config['ns'].$this->config['session_param'], $this->properties['session_id'], time()+3600*24*365*30, "/", $this->config['cookie_domain']);
        
	
		return;
	
	}
	
	/**
	 * Creates new visitor
	 * 
	 * @access 	public
	 *
	 */
	function set_new_visitor() {
	
		// Create guid
        $this->properties['visitor_id'] = $this->set_guid();
		
        // Set visitor cookie
        setcookie($this->config['ns'].$this->config['visitor_param'], $this->properties['visitor_id'] , time()+3600*24*365*30, "/", $this->config['cookie_domain']);
		
		$this->properties['is_new_visitor'] = true;
		
		return;
	
	}
	

	
	
	
	
}

?>
