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
 * Page Views Widget Controller
 *
 *
 */
class owa_widgetSparklineController extends owa_widgetController {
	
	// this is the column name within the results to transform into sparkline
	// data format.
	var $metric_col = 'count';
	
	function __construct($params) {
		
		$this->setDefaultFormat('table');
		
		parent::__construct($params);
		
		if (array_key_exists('metric_col', $this->params)) {
			$this->metric_col = $this->params['metric_col'];
		}
		
	}
	
	function owa_widgetSparklineController($params) {
	
		return owa_widgetSparklineController::__construct($params);
	}

	function action() {
		
		// Set Title of the Widget
		$this->data['title'] = 'Trend';
		
		// set default dimensions
		$this->setHeight(25);
		$this->setWidth(200);
		
		//setup Metrics
		$m = owa_coreApi::metricFactory($this->params['metric']);
		$m->setConstraint('site_id', $this->params['site_id']);
		
		$m->setPeriod($this->getPeriod());
		
		$results = $m->generate();
		
		if (!empty($results)) {
			$res = owa_lib::deconstruct_assoc($results);
			$series = implode(',', $res[$this->metric_col]);
		} else {
			$series = '';
		}
		
		//print_r($series);
		$this->data['series']['values'] = $series;
		$this->data['view'] = 'base.sparklineJs';
		
		return;
		
	}
	
}


?>