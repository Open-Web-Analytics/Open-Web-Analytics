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

require_once(OWA_BASE_DIR.'/owa_lib.php');
require_once(OWA_BASE_DIR.'/owa_view.php');
require_once(OWA_BASE_DIR.'/owa_reportController.php');

/**
 * Latest Visits Widget Controller
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_widgetLatestVisitsController extends owa_reportController {

	function owa_widgetLatestVisitsController($params) {
		
		$this->owa_reportController($params);
		$this->priviledge_level = 'viewer';
	
		return;
	}
	
	function action() {

		// Load the core API
		$api = &owa_coreAPI::singleton($this->params);
		
		$data = array();
		
		$this->e->debug(sprintf("start: %s, end: %s, Now: %s", date("F j, Y, g:i:s a", $this->params['start_time']), date("F j, Y, g:i:s a"), date("F j, Y, g:i:s a", time())));
		
		$data['params'] = $this->params;
		

		$data['latest_visits'] = $api->getMetric('base.latestVisits', array(
		
			'constraints'	=> array('site_id'	=> $this->params['site_id']),
			'limit'			=> 50,
			'orderby'		=> array('session.timestamp'),
			'period'		=> 'time_range',
			'start_time'	=> $this->params['start_time'],
			'end_time'		=> $this->params['last_end_time'],
			'order'			=> 'ASC'
		
		));
		
		
		
		$data['view'] = 'base.widgetLatestVisits';	
		
		return $data;	
		
	}
	
}
		


/**
 * View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_widgetLatestVisitsView extends owa_view {
	
	function owa_widgetLastestVisitsView() {
		
		$this->owa_view();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		
		// load body template
		$this->t->set_template('wrapper_blank.tpl');
		$this->body->set_template('widget_latest_visits.tpl');
			
		$this->body->set('visits', $data['latest_visits']);
		
		return;
	}
	
	
}


?>