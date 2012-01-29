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

require_once(OWA_BASE_CLASS_DIR.'cliController.php');

/**
 * Entity Install Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_processEventQueueController extends owa_cliController {
	
	function __construct($params) {
	
		$this->setRequiredCapability( 'edit_modules' );
		return parent::__construct( $params );
	}

	function action() {
		
		if ( $this->getParam( 'source' ) ) {
			$input_queue_type = $this->getParam( 'source' );
		} else {
			$input_queue_type = owa_coreAPI::getSetting( 'base', 'event_queue_type' );
		}
		
		$processing_queue_type = $this->getParam( 'destination' );
		
		if ( ! $processing_queue_type ) {
			
			$processing_queue_type = owa_coreAPI::getSetting( 'base', 'event_secondary_queue_type' );
		}
			
		// switch event queue setting in case a new events should be sent to a different type of queue.
		// this is handy for when processing from a file queue to a database queue
		if ( $processing_queue_type ) {
			owa_coreAPI::setSetting( 'base', 'event_queue_type', $processing_queue_type );
			owa_coreAPI::debug( "Setting event queue type to $processing_queue_type for processing." );
		}
		
		$d = owa_coreAPI::getEventDispatch();
		owa_coreAPI::debug( "Loading $input_queue_type event queue." );
		$q = $d->getAsyncEventQueue( $input_queue_type );
	
		$ret = $q->processQueue();
		
		// go ahead and process the secondary event queue
		if ( $ret && $processing_queue_type ) {
			$destq = $d->getAsyncEventQueue( $processing_queue_type );
			$destq->processQueue();
		}
	}
}

?>
