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

require_once 'wa_settings_class.php';
require_once 'wa_env.php';
require_once 'owa_db.php';
require_once 'owa_location.php';

/**
 * Session
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_session {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config = array();
	
	/**
	 * Debug
	 *
	 * @var string
	 */
	var $debug;
	
	/**
	 * Database access object
	 *
	 * @var object
	 */
	var $db;
	
	/**
	 * Event queue
	 *
	 * @var object
	 */
	var $eq;
	
	/**
	 * Properties
	 *
	 * @var array
	 */
	var $properties = array();
	
	/**
	 * State
	 *
	 * @var string
	 */
	var $state;
	
	/**
	 * Constructor
	 *
	 * @return owa_session
	 * @access public
	 */
	function owa_session() {
	
		$this->config = &wa_settings::get_settings();
		$this->debug = &owa_lib::get_debugmsgs();
		$this->db = &owa_db::get_instance();
		$this->eq = &eventQueue::get_instance();
		
		return;
	}

	/**
	 * Create new session
	 *
	 * @param 	array $request
	 * @access 	public
	 */
	function process_new_session($request) {
		
		// set properties
    	$this->properties['session_id'] = $request['session_id'];
		$this->properties['visitor_id'] = $request['visitor_id'];
		$this->properties['user_name'] = $request['user_name'];
		$this->properties['user_email'] = $request['user_email'];
		$this->properties['timestamp'] = $request['timestamp'];
		$this->properties['year'] = $request['year'];
		$this->properties['month'] = $request['month'];
		$this->properties['day'] = $request['day'];
		$this->properties['dayofweek'] = $request['dayofweek'];
		$this->properties['dayofyear'] = $request['dayofyear'];
		$this->properties['weekofyear'] = $request['weekofyear'];
		$this->properties['hour'] = $request['hour'];
		$this->properties['minute'] = $request['minute'];
		$this->properties['last_req'] = $request['timestamp'];
		$this->properties['num_pageviews'] = 1;
		
		$this->properties['prior_session_lastreq'] = $request['last_req'];
		$this->properties['prior_session_id'] = $request['inbound_session_id'];
		
		if ($this->properties['prior_session_lastreq'] > 0):
			$this->properties['time_sinse_priorsession'] =  $this->properties['timestamp'] - $this->properties['prior_session_lastreq'];
			$this->properties['prior_session_year'] = date("Y", $this->properties['prior_session_lastreq']);
			$this->properties['prior_session_month'] = date("M", $this->properties['prior_session_lastreq']);
			$this->properties['prior_session_day'] = date("d", $this->properties['prior_session_lastreq']);
			$this->properties['prior_session_hour'] = date("G", $this->properties['prior_session_lastreq']);
			$this->properties['prior_session_minute'] = date("i", $this->properties['prior_session_lastreq']);
			$this->properties['prior_session_dayofweek'] = date("w", $this->properties['prior_session_lastreq']);
		endif;
		
		$this->properties['os'] = $request['os'];	
		$this->properties['os_id'] = $request['os_id'];
		$this->properties['ua'] = $request['ua'];	
		$this->properties['ua_id'] = $request['ua_id'];
		$this->properties['browser_type'] = $request['browser_type'];
	
		$this->properties['first_page_uri'] = $request['uri'];
		$this->properties['first_page_type'] = $request['page_type'];
		$this->properties['first_page_id'] = $request['document_id'];
		$this->properties['first_page_title'] = $request['page_title'];
		
		$this->properties['last_page_uri'] = $request['uri'];
		$this->properties['last_page_type'] = $request['page_type'];
		$this->properties['last_page_id'] = $request['document_id'];
		$this->properties['last_page_title'] = $request['page_title'];
		
		$this->properties['referer'] = $request['referer'];
		$this->properties['referer_id'] = $request['referer_id'];
		$this->properties['ip_address'] = $request['ip_address'];
		$this->properties['host'] = $request['host'];
		$this->properties['host_id'] = $request['host_id'];
		
		$this->properties['site'] = $request['site'];
		$this->properties['site_id'] = $request['site_id'];
		$this->properties['is_new_visitor'] = $request['is_new_visitor'];
		$this->properties['is_repeat_visitor'] = $request['is_repeat_visitor'];
		
		$this->properties['is_browser'] = $request['is_browser'];
		$this->properties['is_robot'] = $request['is_robot'];
		$this->properties['is_feedreader'] = $request['is_feedreader'];
		
		$this->properties['source'] = $request['source'];
		$this->properties['city'] = $request['city'];
		$this->properties['country'] = $request['country'];
		
		if ($this->config['geolocation_lookup'] == true):
			$this->get_location();
		endif;
		
		$this->log_initial_session();
		
		return;
	}
	
	/**
	 * Log new session to databse
	 *
	 * @access 	privit
	 */
	function log_initial_session() {
	
		$browser_session = array(
						'session_id',
						'visitor_id',
						'user_name',
						'user_email',
						'timestamp',
						'year',
						'month',
						'day',
						'dayofweek',
						'dayofyear',
						'weekofyear',
						'hour',
						'minute',
						'last_req',
						'num_pageviews',
						'num_comments',
						'is_repeat_visitor',
						'is_new_visitor',
						'prior_session_lastreq',
						'prior_session_id',
						'time_sinse_priorsession',
						'prior_session_year',
						'prior_session_month',
						'prior_session_day',
						'prior_session_dayofweek',
						'prior_session_hour',
						'prior_session_minute',
						'os',
						'os_id',
						'ua_id',
						'first_page_id',
						'last_page_id',
						'referer_id',
						'ip_address',
						'city',
						'country',
						'source',
						'host',	
						'host_id',
						'site',
						'site_id',
						'is_browser',
						'is_robot',
						'is_feedreader',
					);
					
			foreach ($browser_session as $key => $value) {
			
				$sql_cols = $sql_cols.$value;
				$sql_values = $sql_values."'".$this->properties[$value]."'";
				
				if (!empty($browser_session[$key+1])):
				
					$sql_cols = $sql_cols.", ";
					$sql_values = $sql_values.", ";
					
				endif;	
			}
		
		// log the request to the destination
		
			$this->db->query(
				sprintf("
					INSERT into %s (%s) VALUES (%s)",
					
					$this->config['ns'].$this->config['sessions_table'],
					$sql_cols,
					$sql_values
				)
			);
	
	
		$this->state = 'new_session';
		
		// send session to event queue
		$this->eq->log($this, $this->state);
		
		return;	
	}
	
	
	/**
	 * Log updated session
	 *
	 * @param 	array $request
	 * @access 	public
	 */
	function update_current_session($request) {
		
		$this->properties['visitor_id'] = $request['visitor_id'];
		$this->properties['session_id'] = $request['session_id'];
		$this->properties['last_req'] = $request['last_req'];
		$this->properties['num_pageviews'] = 1;
		$this->properties['num_comments'] = 0;
		$this->properties['last_page_uri'] = $request['uri'];
		$this->properties['last_page_type'] = $request['page_type'];
		$this->properties['last_page_id'] = $request['document_id'];
		$this->properties['last_page_title'] = $request['page_title'];
		$this->properties['user_email'] = $request['user_email'];
		$this->properties['user_name'] = $request['user_name'];
		$this->properties['host'] = $request['host'];
									
		$this->db->query(
     		 sprintf(
				"UPDATE
					%s
				 SET 
					last_req = '%s',
					num_pageviews = num_pageviews + %s,
					num_comments = num_comments + %s,
					last_page_id = '%s',
					user_email = '%s'
				 WHERE
					session_id = '%s'",
					
					$this->config['ns'].$this->config['sessions_table'],
					$this->properties['last_req'],
					$this->properties['num_pageviews'],
					$this->properties['num_comments'],
					$this->properties['last_page_id'],
					$this->properties['user_email'],
					$this->properties['session_id']
      		)
    	);
	
		$this->state = 'session_update';
		
		// Send updated sesion to the event queue
		$this->eq->log($this, $this->state);
		
		return;
	}
	
	/**
	 * Gets geo-location from 3rd party service
	 * 
	 * @access 	private
	 */
	function get_location() {
		
		// makes the geo-location object from the service specified in the config
		$location = owa_location::factory($this->config['plugin_dir']."location/", $this->config['geolocation_service']);
		
		// lookup
		$location->get_location($this->properties['ip_address']);
		
		//set properties of the session
		$this->properties['country'] = $location->country;
		$this->properties['city'] = $location->city;
		$this->properties['latitude'] = $location->latitude;
		$this->properties['longitude'] = $location->longitude;
	
		return;
	}
	
}

?>
