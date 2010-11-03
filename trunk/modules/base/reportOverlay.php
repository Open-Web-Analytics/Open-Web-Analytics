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

class owa_reportOverlayController extends owa_reportController {
	
	function action() {
		
		// Fetch document object
		$d = owa_coreAPI::entityFactory('base.document');
		
		if ($this->getParam('document_id')) {
			$document_id = $this->getParam('document_id');
		} else {
			$eq = owa_coreAPI::getEventDispatch();
			$document_id = $d->generateId( $eq->filter('page_url',  urldecode( $this->getParam('document_url') ), $this->getParam( 'siteId' ) ) );
		}
			
		$d->getByColumn('id', $document_id);
		$this->set('document_details', $d->_getProperties());
		$this->set('document_id', $document_id);
		
		// Get clicks
		$c = owa_coreAPI::metricFactory('base.topClicks');
		$c->setPeriod($this->getPeriod());
		$c->setConstraint('site_id', $this->getParam('site_id')); 
		$c->setConstraint('document_id', $d->get('id'));
		
		//$c->setConstraint('ua_id', $this->getParam('ua_id'));
		$limit = $this->getParam('limit');
		if (!$limit) {
			$limit = 100;
		}
		
		if ($this->getParam('page')) {
			$c->setPage($this->getParam('page'));
		}
		
		$c->setLimit($limit);
		$clicks = $c->generateResults();
		//print_r($clicks);
		$this->set('json', $clicks);
		
		// set view stuff
		$this->setView('base.json');	
	}
	
}

?>