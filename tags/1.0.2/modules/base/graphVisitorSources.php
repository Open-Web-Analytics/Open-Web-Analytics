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
require_once(OWA_BASE_CLASSES_DIR.'owa_coreAPI.php');
require_once(OWA_BASE_CLASS_DIR.'abstractJpGraphView.php');

/**
 * Visitor Type Pie Graph View
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_graphVisitorSourcesView extends owa_abstractJpGraphView  {
	
	function owa_graphVisitorSourcesView() {
		
		$this->owa_abstractJpGraphView();
		$this->priviledge_level = 'viewer';
		
		return;
	}
	
	function construct($data) {
		
		$api = &owa_coreAPI::singleton($data);
		
		$from_feeds = $api->getMetric('base.visitsFromFeedsCount',array(
		
		'request_params'	=>	$data,
		'period'			=> $data['period'],
		'result_format'		=> 'single_array',
		'constraints'		=> array(
			'site_id'	=> $data['site_id']
		
		)
	
		));
		
		$from_se = $api->getMetric('base.visitsFromSearchEnginesCount',array(
		
		'request_params'	=>	$data,
		'period'			=> $data['period'],
		'result_format'		=> 'single_array',
		'constraints'		=> array(
			'site_id'	=> $data['site_id']
		
		)
	
		));
		
		$from_sites = $api->getMetric('base.visitsFromSitesCount', array(
		
		'request_params'	=>	$data,
		'period'			=> $data['period'],
		'result_format'		=> 'single_array',
		'constraints'		=> array(
			'site_id'	=> $data['site_id']
		
		)
	
		));
		
		$from_direct = $api->getMetric('base.visitsFromDirectNavCount', array(
		
		'request_params'	=>	$data,
		'period'			=> $data['period'],
		'result_format'		=> 'single_array',
		'constraints'		=> array(
			'site_id'		=> $data['site_id']
		
		)
	
		));
		
		$data_pie  = array($from_feeds['source_count'], $from_se['se_count'], $from_sites['site_count'], $from_direct['count']);
		
		if(array_sum($data_pie) == 0):
		
			$this->graph = owa_coreAPI::graphFactory('base.jpErrorGraph');
			$this->graph->params['width'] = 275;
			$this->graph->params['height'] = 100;
			$this->graph->params['error_msg'] = $this->getMsg(3500);
		
		else:
		
			// Graph Params	
			$this->graph = owa_coreAPI::graphFactory('base.jpPieGraph');
			$this->graph->params['graph_title'] = "Visit Sources for " . $this->graph->get_period_label($data['period']);
			$this->graph->params['legends'] = array('Feeds', 'Search Engines', 'Web Sites', 'Direct');
			$this->graph->params['height']	= 220;
			$this->graph->params['width']	= 350;
			$this->graph->params['data']['data_pie']  = $data_pie;
			$this->graph->params['legends_cols'] = 4;
			
			//print_r($data_pie);
			
		endif;
		
		return;
	}
	
	
}



?>