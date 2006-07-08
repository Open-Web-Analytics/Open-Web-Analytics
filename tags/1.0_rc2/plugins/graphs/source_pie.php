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

/**
 * PIe Graph of Traffic Sources
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_graph_source_pie extends owa_graph {	

	/**
	 * Constructor
	 *
	 * @access 	public
	 * @return 	owa_graph_visitors_pie
	 */
	function owa_graph_source_pie() {
		
		$this->owa_graph();
		$this->api_calls = array('source_pie');
		
		return;
	}

	/**
	 * Generate Graph
	 *
	 * @param 	array $params
	 * @access 	public
	 * @return 	unknown
	 */
	function generate($params) {
			
		$this->params = $params;
	
		switch ($params['api_call']) {
		
			case "source_pie":
				
				return $this->graph_source_pie();
				
			}
		
		return;
	}
	
	/**
	 * Assembles Graph
	 *
	 * @access 	private
	 */
	function graph_source_pie() {
	
		$from_feeds = $this->metrics->get(array(
		
		'request_params'	=>	$this->params,
		'api_call' 			=> 'from_feed',
		'period'			=> $this->params['period'],
		'result_format'		=> 'assoc_array',
		'constraints'		=> array(
			'site_id'	=> $this->params['site_id']
		
		)
	
		));
		
		$from_se = $this->metrics->get(array(
		
		'request_params'	=>	$this->params,
		'api_call' 			=> 'from_search_engine',
		'period'			=> $this->params['period'],
		'result_format'		=> 'assoc_array',
		'constraints'		=> array(
			'site_id'	=> $this->params['site_id']
		
		)
	
		));
		
		$from_sites = $this->metrics->get(array(
		
		'request_params'	=>	$this->params,
		'api_call' 			=> 'from_sites',
		'period'			=> $this->params['period'],
		'result_format'		=> 'assoc_array',
		'constraints'		=> array(
			'site_id'	=> $this->params['site_id']
		
		)
	
		));
		
		// Graph Params	
		$this->params['graph_title'] = "Traffic Sources for \n" . $this->get_period_label($this->params['period']);
		$this->params['legends'] = array('Feeds', 'Search Engines', 'Web Sites');
		$this->params['height']	= 200;
		$this->params['width']	= 280;
		
		// Construct Data Array
		$result_pie = array($from_feeds['source_count'], $from_se['se_count'], $from_sites['site_count']);
		
		if(array_sum($result_pie) == 0):
		
			$this->error_graph();
		
		else:
		
			// Asign Data to graph
			$this->data = array(
			
				'data_pie'		=> $result_pie
				
				);
				
			$this->pie_graph();
			
		endif;
		
		return;
	}
	
}

?>
