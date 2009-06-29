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

require_once(OWA_BASE_CLASSES_DIR.'owa_lib.php');
require_once(OWA_BASE_CLASSES_DIR.'owa_controller.php');
require_once(OWA_BASE_CLASSES_DIR.'owa_view.php');

/**
 * Visitor Type Widget
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_visitorTypesWidgetController extends owa_controller  {
	
	function owa_visitorTypesWidgetController($params) {
		
		
		return owa_visitorTypesWidgetController::__construct($params);
	}
	
	function __construct($params) {
		
		return parent::__construct($params);
	}
	
	
	function action() {
		
		$data = array();
				
		// count from feeds
		$f = owa_coreAPI::metricFactory('base.visitsFromFeedsCount');
		$f->setConstraint('site_id', $this->params['site_id']);
		$f->setPeriod($this->params['period']);
		$data['values']['feeds'] = $f->generate();
		$data['labels']['feeds'] = 'Feeds';
		
		// count from search engines
		$se = owa_coreAPI::metricFacory('base.visitsFromSearchEnginesCount');
		$se->setConstraint('site_id', $this->params['site_id']);
		$se->setPeriod($this->params['period']);
		$data['values'][] = $se->generate();
		$data['labels'][] = 'Search Engines';	
	
		// count from refering web sites
		$s = owa_coreAPI::metricFactory('base.visitsFromSitesCount');
		$s->setConstraint('site_id', $this->params['site_id']);
		$s->setPeriod($this->params['period']);
		$data['values'][] = $se->generate();
		$data['labels'][] = 'Web Sites';	
		
		// count from refering web sites
		$d = owa_coreAPI::metricFactory('base.visitsFromDirectNavCount');
		$d->setConstraint('site_id', $this->params['site_id']);
		$d->setPeriod($this->params['period']);
		$data['values'][] = $se->generate();
		$data['labels'][] = 'Direct Navigation';	
			
		// title	
		$data['title'] = 'Visitor Types';
				
		// setup proper view
		if (array_key_exists('format', $this->params)):
			$format = $this->params['format'];
		else:
			$format = 'graph';
		endif;
		
		switch ($format) {
		
			case 'graph':
						
				$data['view'] = 'base.pieFlashChart';
				break;
				
			case 'table':
				$data['column_labels'] = array();
				$data['data'] = '';
				$data['view'] = 'base.genericTable';
				break;
				
		}
		
		return $data;

	}
	
	
}



?>