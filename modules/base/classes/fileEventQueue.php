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

require_once(OWA_BASE_CLASS_DIR.'eventQueue.php');
require_once(OWA_PEARLOG_DIR . DIRECTORY_SEPARATOR . 'Log.php');
require_once(OWA_PLUGIN_DIR . 'log/queue.php');
require_once(OWA_PLUGIN_DIR . 'log/async_queue.php');

/**
 * http Event Queue
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_fileEventQueue extends owa_eventQueue {
	
	var $queue;
		
	function makeQueue() {
		
		//make file queue
		$conf = array('mode' => 0600, 'timeFormat' => '%X %x');
		$this->q = &Log::singleton('async_queue', owa_coreAPI::getSetting('base', 'async_log_dir').owa_coreAPI::getSetting('base', 'async_log_file'), 'async_event_queue', $conf);
		$this->q->_lineFormat = '%1$s|*|%2$s|*|[%3$s]|*|%4$s|*|%5$s';
		// not sure why this is needed but it is.
		$this->q->_filename	= owa_coreAPI::getSetting('base', 'async_log_dir').owa_coreAPI::getSetting('base', 'async_log_file');
	}
	
	function addToQueue($event) {
		
		if (!$this->q) {
			$this->makeQueue();
		}
		
		$this->q->log($event->getProperties(), $event->getEventType());
	
	}
	
	function processQueue() {
	
	}
	
	

}

?>