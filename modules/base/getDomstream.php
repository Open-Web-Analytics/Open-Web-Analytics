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

require_once(OWA_BASE_DIR.'/owa_reportController.php');
require_once(OWA_BASE_DIR.'/owa_view.php');

if (!class_exists('Services_JSON')) {
	require_once(OWA_INCLUDE_DIR.'JSON.php');
}


/**
 * Overlay Report Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_getDomstreamController extends owa_reportController {

	function action() {
		
		// Fetch document object
		$d = owa_coreAPI::entityFactory('base.domstream');
		$d->load($this->getParam('domstream_id'));
		$json = new Services_JSON();
		$d->set('events', $json->decode($d->get('events')));
		
		$db = owa_coreAPI::dbSingleton();
		$db->select('*');
		$db->from( $d->getTableName() );
		$db->where( 'domstream_guid', $this->getParam('domstream_guid') );
		$db->order('timestamp', 'ASC');
		$ret = $db->getAllRows();
		
		$combined = array();
		foreach ($ret as $row) {
			$combined = $this->mergeStreamEvents( $row['events'], $combined );
		}
		
		$row['events'] = json_decode($combined);
		$this->set('json', $row);
		// set view stuff
		$this->setView('base.json');	
	}
	
	function mergeStreamEvents($new, $old = '') {
    	
    	if ( $old ) {
    		$old = json_decode($old);
    		owa_coreAPI::debug('old: '.print_r($old, true));
    		$new = json_decode($new);
    		owa_coreAPI::debug('new: '.print_r($new, true));
    		$combined = array_merge($old, $new);
    		owa_coreAPI::debug('combined: '.print_r($combined, true));
    		owa_coreAPI::debug('combined count: '.count($combined));
    		$combined = json_encode($combined);
    		return $combined;
    	} else {
    		return $new;
    	}
    }   
}

?>