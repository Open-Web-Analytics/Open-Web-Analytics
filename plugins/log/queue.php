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

/**
 * The Log_queue class is a concrete implementation of the Log:: abstract
 * class.  It simply consumes log events.
 *
 * @author  Peter Adams <peter@openwebanalytics.com>
 * @since   OWA 1.0
 * @package OWA
 *
 */
class Log_queue extends Log {

	/**
     * The default event type to use when logging an event.
     *
     * @var string
     * @access private
     */
    var $_event_type = '';


    /**
     * Constructs a new Log_null object.
     *
     * @param string $name     Ignored.
     * @param string $ident    The identity string.
     * @param array  $conf     The configuration array.
     * @param int    $level    Log messages up to and including this level.
     * @access public
     */
    function Log_queue($name, $ident = '', $conf = array(), $level = PEAR_LOG_DEBUG) {

    	$this->_id = md5(microtime());
        $this->_ident = $ident;
        $this->_mask = Log::UPTO($level);
        
        return;
    }

    /**
     * Simply consumes the log event.  The message will still be passed
     * along to any Log_observer instances that are observing this Log.
     *
     * @param string $event_type	The type of event being logged to the queue
     * @param mixed  $message    String or object containing the message to log.
     * @param string $priority The priority of the message.  Valid
     *                  values are: PEAR_LOG_EMERG, PEAR_LOG_ALERT,
     *                  PEAR_LOG_CRIT, PEAR_LOG_ERR, PEAR_LOG_WARNING,
     *                  PEAR_LOG_NOTICE, PEAR_LOG_INFO, and PEAR_LOG_DEBUG.
     * @return boolean  True on success or false on failure.
     * @access public
     */
    function log($message, $event_type, $priority = null)
    {
        /* If a priority hasn't been specified, use the default value. */
        if ($priority === null) {
            $priority = $this->_priority;
        }

        /* Abort early if the priority is above the maximum logging level. */
        if (!$this->_isMasked($priority)) {
            return false;
        }
		
		$this->_event_type = $event_type;
		
        $this->_announce(array('event_type' => $event_type, 'priority' => $priority, 'message' => $message));
		
        return true;
    }
	
	/**
     * Informs observers that a new message of a particular event type has been
     * logged.
     *
     * @param array     $event      A hash describing the log event.
     *
     * @access private
     */
    function _announce($event) {
		
        foreach ($this->_listeners as $id => $listener) {
            if ($event['priority'] <= $this->_listeners[$id]->_priority) {
				
				if (array_search($event['event_type'], $this->_listeners[$id]->_event_type) !== false):
				
					$this->_listeners[$id]->notify($event);
					
				endif;
            }
        }
    }
	
}
