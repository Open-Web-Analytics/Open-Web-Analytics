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

require_once(OWA_BASE_DIR ."/owa_db.php");

/**
 * Document Event Handler
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class Log_observer_document extends owa_observer {

	/**
	 * Database Access Object
	 *
	 * @var object
	 */
	var $db;

	/**
	 * Constructor
	 *
	 * @param 	string $priority
	 * @param 	array $conf
	 * @access 	public
	 * @return 	Log_observer_document
	 */
    function Log_observer_document($priority, $conf) {
		
    	
        // Call the base class constructor.
        $this->owa_observer($priority);

        // Configure the observer.
		$this->_event_type = array('new_request', 'feed_request');

		// Load DOA
		$this->db = &owa_db::get_instance();	
	
		return;
    }

    /**
     * Notify Handler
     *
     * @access 	public
     * @param 	object $event
     */
    function notify($event) {
		
    	$this->e->debug('Document being handled');
    	
		$this->m = $event['message'];
	
		$this->insert_document();
						
		return;
	}
		
	/**
	 * Adds document data to documents table.
	 * 
	 * @access private
	 */
	function insert_document() {
	
		return $this->db->query(
				sprintf(
					"INSERT into %s (id, url, page_title, page_type) VALUES ('%s', '%s', '%s', '%s')",
					$this->config['ns'].$this->config['documents_table'],
					$this->m['document_id'],
					$this->m['uri'],
					$this->m['page_title'],
					$this->m['page_type']
				)
			);	
	}
	
}

?>
