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
require_once 'wa_lib.php';
require_once 'eventQueue.php';

/**
 * Comment
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_comment {
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config;
	
	/**
	 * Comment Properties
	 *
	 * @var array
	 */
	var $properties;
	
	/**
	 * Event Queue
	 *
	 * @var object
	 */
	var $eq;
	
	/**
	 * State
	 *
	 * @var string
	 */
	var $state;
	
	/**
	 * Constructor
	 * @access public
	 */
	function owa_comment() {
		
		$this->config = &wa_settings::get_settings();
		$this->debug = &wa_lib::get_debugmsgs();
		$this->eq = &eventQueue::get_instance();
		
		// Retriece inbound vistor and session values	
		$this->properties['inbound_visitor_id'] = $_COOKIE[$this->config['ns'].$this->config['visitor_param']];
		$this->properties['inbound_session_id'] = $_COOKIE[$this->config['ns'].$this->config['session_param']];
		
		// Record time of last request
		$this->properties['last_req'] = $_COOKIE[$this->config['ns'].$this->config['last_request_param']];
		
		return;
	}
	
	function log() {

		$this->eq->log($this, $this->state);
		return;
	}
}

?>