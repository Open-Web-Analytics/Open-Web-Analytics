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

require_once 'owa_event_class.php';
require_once 'owa_db.php';
require_once 'owa_lib.php';
require_once 'ini_db.php';

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

class owa_click extends owa_event {
	
	/**
	 * Constructor
	 *
	 * @return owa_request
	 * @access public
	 */
	function owa_click() {
		
		//Call to Parent Constructor
		$this->owa_event();
		$this->properties['visitor_id'] = $this->properties['inbound_visitor_id'];
		$this->properties['session_id'] = $this->properties['inbound_session_id'];
		
		return;
	
	}
	
	/**
	 * Controller Logic for first setting up event
	 *
	 */
	function process() {
		
		// Make ua id
		$this->properties['ua_id'] = $this->set_string_guid($this->properties['ua']);
		
		// Make os id
		//$this->properties['os'] = $this->determine_os($this->properties['ua']);
		//$this->properties['os_id'] = $this->set_string_guid($this->properties['os']);
	
		// Make document id	
		$this->properties['page_url']= $this->stripDocumentUrl($this->properties['page_url']);
		$this->properties['document_id'] = $this->set_string_guid($this->properties['page_url']); 
		
		//$this->setDocumentProperties($this->properties['page_url']);
		$this->properties['target_url'] = $this->stripDocumentUrl($this->properties['target_url']);
		$this->properties['target_id'] = $this->set_string_guid($this->properties['target_url']);
		// Resolve host name
		if ($this->config['resolve_hosts'] = true):
			$this->resolve_host();
		endif;
		
		// Determine Browser type
		$this->determine_browser_type();
		
		$this->e->debug('click properties: '.print_r($this->properties, true));
		
		$this->state = 'click';
		
		$this->log();
		
		return;
	}
	
	/**
	 * Saves Click to database
	 *
	 */
	function save() {
		
		$this->db = &owa_db::get_instance();
		
		$this->db->query(sprintf(
			"INSERT into %s (
				click_id,
				last_impression_id,
				visitor_id,
				session_id,
				document_id,
				tag_id,
				placement_id,
				campaign_id,
				ad_group_id,
				ad_id,
				site_id,
				ua_id,
				host_id,
				target_id,
				timestamp,
				year,
				month,
				day,
				dayofyear,
				hour,
				minute,
				second,
				msec,
				target_url,
				click_x,
				click_y,
				dom_element_x,
				dom_element_y,
				dom_element_name,
				dom_element_id,
				dom_element_value,
				dom_element_tag,
				dom_element_text,
				ip_address,
				host
				)
			values 
				('%d', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', 
				'%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s',
				'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )",
			$this->config['ns'].$this->config['clicks_table'],
			$this->properties['guid'],
			$this->properties['last_impression_id'],
			$this->properties['visitor_id'],
			$this->properties['session_id'],
			$this->properties['document_id'],
			$this->properties['tag_id'],
			$this->properties['placement_id'],
			$this->properties['campaign_id'],
			$this->properties['ad_group_id'],
			$this->properties['ad_id'],
			$this->properties['site_id'],
			$this->properties['ua_id'],
			$this->properties['host_id'],
			$this->properties['target_id'],
			$this->properties['timestamp'],
			$this->properties['year'],
			$this->properties['month'],
			$this->properties['day'],
			$this->properties['dayofyear'],
			$this->properties['hour'],
			$this->properties['minute'],
			$this->properties['second'],
			$this->properties['msec'],
			$this->properties['target_url'],
			$this->properties['click_x'],
			$this->properties['click_y'],
			$this->properties['dom_element_x'],
			$this->properties['dom_element_y'],
			$this->properties['dom_element_name'],
			$this->properties['dom_element_id'],
			$this->properties['dom_element_value'],
			$this->properties['dom_element_tag'],
			$this->properties['dom_element_text'],
			$this->properties['ip_address'],
			$this->properties['host']

			)
		);	
		
		return;
	}
	
	
	function load($primaryKey) {
		
		return;
	}
	
	
	/**
	 * Transform current request. Assign IDs
	 *
	 * @access 	public
	 */
	function transform_request() {
			
		// Make ua id
		$this->properties['ua_id'] = $this->set_string_guid($this->properties['ua']);
		
		// Make os id
		$this->properties['os'] = $this->determine_os($this->properties['ua']);
		$this->properties['os_id'] = $this->set_string_guid($this->properties['os']);
	
		// Make document id	
		$this->properties['document_id'] = $this->make_document_id();
		// Resolve host name
		if ($this->config['resolve_hosts'] = true):
			$this->resolve_host();
		endif;
		
		// Determine Browser type
		$this->determine_browser_type();
		
		//update last-request time cookie
		setcookie($this->config['ns'].$this->config['last_request_param'], $this->properties['sec'], time()+3600*24*365*30, "/", $this->properties['site']);
		
		return;			
		
	}
	
	
	/**
	 * Determines the time sinse the last request from this borwser
	 * 
	 * @access private
	 * @return integer
	 */
	function time_sinse_last_request() {
	
        return ($this->properties['timestamp'] - $this->properties['last_req']);
	
	}
	
	/**
	 * Determine the type of browser
	 * 
	 * @access 	private
	 */
	function determine_browser_type() {
		
		$this->properties['browser_type'] = $this->browscap->browser;
		
		return;
	}
	
	
	
}

?>
