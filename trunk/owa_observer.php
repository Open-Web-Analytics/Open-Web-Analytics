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

require_once(OWA_PEARLOG_DIR . '/Log/observer.php');

/**
 * WA Observer extends PEAR Log's observer to add event type.
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_observer extends Log_observer {

	 /**
     * The type of event that an observer would want to hear about.
     *
     * @var string
     * @access private
     */
    var $_event_type = array();
	
	
	 /**
     * The directory that concrete classes can be found.
     *
     * @var string
     * @access private
     */
    var $_plugin_path;

    /**
     * Creates a new basic Log_observer instance.
     *
     * @param integer   $priority   The highest priority at which to receive
     *                              log event notifications.
     *
     * @access public
     */
    function owa_observer($priority = PEAR_LOG_INFO)
    {
        $this->Log_observer($priority);
    	//$this->_id = md5(microtime());
        //$this->_priority = $priority;
    }

}
