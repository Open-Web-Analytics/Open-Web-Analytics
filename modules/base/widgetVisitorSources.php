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
require_once(OWA_BASE_CLASS_DIR.'widget.php');

/**
 * Visitor Source Widget Controller
 *
 *
 */
class owa_widgetVisitorSourcesController extends owa_widgetController {
	
	function __construct($params) {
		
		$this->setDefaultFormat('graph');
		
		return parent::__construct($params);
	}
	
	function owa_widgetVisitorSourcesController($params) {
	
		return owa_widgetVisitorSourcesController::__construct($params);
	}

	function action() {
		
		// Set Title of the Widget
		$this->data['title'] = 'Visitor Sources';
		
		// set default dimensions
		$this->setHeight(450);
		$this->setWidth(350);
		
		// enable formats
		$this->enableFormat('graph');
		$this->enableFormat('table');
	
		//Metrics
		$f = owa_coreApi::metricFactory('base.visitsFromFeedsCount');
		$f->setConstraint('site_id', $this->params['site_id']);
		$f->setConstraint('is_browser', 1);
		$f->setPeriod($this->getPeriod());
		$this->setMetric('base.visitsFromFeedsCount', $f);
		 
		$se = owa_coreApi::metricFactory('base.visitsFromSearchEnginesCount');
		$se->setConstraint('site_id', $this->params['site_id']);
		$se->setConstraint('is_browser', 1);
		$se->setPeriod($this->getPeriod());
		$this->setMetric('base.visitsFromSearchEnginesCount', $se);
		
		$s = owa_coreApi::metricFactory('base.visitsFromSitesCount');
		$s->setConstraint('site_id', $this->params['site_id']);
		$s->setConstraint('is_browser', 1);
		$s->setPeriod($this->getPeriod());
		$this->setMetric('base.visitsFromSitesCount', $s);
		 
		$d = owa_coreApi::metricFactory('base.visitsFromDirectNavCount');
		$d->setConstraint('site_id', $this->params['site_id']);
		$d->setConstraint('is_browser', 1);
		$d->setPeriod($this->getPeriod());
		$this->setMetric('base.visitsFromDirectNavCount', $d);
					
		return;
		
	}
	
	function graphAction() {
		$this->data['view'] = 'base.openFlashChart';
		return;
	}
	
	function graphDataAction() {
	
		$f = $this->getMetric('base.visitsFromFeedsCount');
		$se = $this->getMetric('base.visitsFromSearchEnginesCount');
		$s = $this->getMetric('base.visitsFromSitesCount');
		$d = $this->getMetric('base.visitsFromDirectNavCount');
		
		$from_feeds = $f->generate();
		$from_se = $se->generate(); 
		$from_sites = $s->generate();
		$from_direct = $d->generate();		
	
		$this->data['values'] = array();
		$this->data['labels'] = array();
		$this->data['width'] = '100%';
		$this->data['height'] = '100%';
		
		if ($from_direct['count'] > 0):
			$this->data['values'][] = $from_direct['count'];
			$this->data['labels'][] = 'Direct';
		endif;
		
		if ($from_se['count'] > 0):
			$this->data['values'][] = $from_se['count'];
			$this->data['labels'][] = 'Search';
		endif;
		
		if ($from_sites['count'] > 0):
			$this->data['values'][] = $from_sites['count'];
			$this->data['labels'][] = 'Sites';
		endif;
		
		if ($from_feeds['count'] > 0):
			$this->data['values'][] = $from_feeds['count'];
			$this->data['labels'][] = 'Feeds';
		endif;
		
		$this->data['view'] = 'base.pieFlashChart';
		return;
	
	}
	
	function tableAction() {
	
		$f = $this->getMetric('base.visitsFromFeedsCount');
		$se = $this->getMetric('base.visitsFromSearchEnginesCount');
		$s = $this->getMetric('base.visitsFromSitesCount');
		$d = $this->getMetric('base.visitsFromDirectNavCount');
		
		$from_feeds = $f->generate();
		$from_se = $se->generate(); 
		$from_sites = $s->generate();
		$from_direct = $d->generate();	
		
		$this->data['labels'] = array('Source', 'Visits');
				
		$results = array();
		$results[] = array('Direct', $from_direct['count']);
		$results[] = array('Search Engines', $from_se['count']);
		$results[] = array('Sites', $from_sites['count']);
		$results[] = array('Feeds', $from_feeds['count']);
		$this->data['rows'] = $results;
		
		$this->data['view'] = 'base.genericTable';
		
		return;

	}
	
	
}

?>