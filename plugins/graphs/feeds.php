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
 * PIe Graph of New Vs. Repeat Visitors
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_graph_feeds extends owa_graph {	

	/**
	 * Constructor
	 *
	 * @access 	public
	 * @return 	owa_graph_visitors_pie
	 */
	function owa_graph_feeds() {
		
		$this->owa_graph();
		$this->api_calls = array('feed_fetches', 'feed_reader_uas', 'feed_formats');
		
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
		
			case "feed_fetches":
				return $this->graph_feed_fetches();
			case "feed_reader_uas":
				return $this->graph_feed_reader_uas();
			case "feed_formats":
				return $this->graph_feed_formats();
		}
		
		return;
	}
	
	/**
	 * Assembles Graph
	 *
	 * @access 	private
	 */
	function graph_feed_fetches() {
		
		// Data 
		$result = $this->metrics->get(array(
		
			'request_params'	=>	$this->params,
			'api_call' 			=> 'feed_fetches_trend',
			'period'			=> $this->params['period'],
			'result_format'		=> 'inverted_array',
			'group_by'			=> 'year, month, day',
			'constraints'		=> array(
				'site_id'		=> $this->params['site_id'])
		));
	
		//Graph params
		
		$this->params['width'] = 900;
		$this->params['height'] = 200;
		$this->params['graph_title'] = "Feed Fetches & Unique Feed Readers for " . $this->get_period_label($this->params['period']);
		$this->params['y2_title'] = "Feed Fetches";
		$this->params['y1_title'] = "Feed Readers";
		
		if(empty($result['fetch_count']) && empty($result['reader_count'])):
			$this->params['width'] = 250;
			$this->params['height'] = 100;
			$this->error_graph();
		else:
			$date = $this->make_date_label($result['day'], $result['month']);
			$this->data = array(
							'y2'	=> $result['fetch_count'],
							'y1'	=> $result['reader_count'],
							'x'		=> $date
						);
		
			
			$this->graph($this->params['type']);
		endif;
				
		return;
	}
	
	function graph_feed_reader_uas() {
		
		$result = $this->metrics->get(array(
		
		'request_params'	=>	$this->params,
		'api_call' 		=> 'count_feed_readers_by_ua',
		'period'			=> $this->params['period'],
		'result_format'		=> 'inverted_array',
		'constraints'		=> array(
			'site_id'		=> $this->params['site_id']
			
			)
	
		));

		// Graph params
		$this->params['graph_title'] = "Feed Reader Types for \n" . $this->get_period_label($this->params['period']);
		$this->params['legends'] = $result['browser_type'];
		$this->params['height']	= 400;
		$this->params['width']	= 400;
		$this->params['slice_label'] = 'Users';
		
		// Graph Data Assignment
		if (empty($result)):
				$this->error_graph();
			return;
		else:
		
			$this->data = array(
		
				'data_pie'		=> $result['count']
			
			);
	
				
			$this->pie_graph();
		endif;
				
		return;
	}
	
	function graph_feed_formats() {
		
		$result = $this->metrics->get(array(
		
		'request_params'	=>	$this->params,
		'api_call' 		=> 'count_feed_fetches_by_format',
		'period'			=> $this->params['period'],
		'result_format'		=> 'inverted_array',
		'constraints'		=> array(
			'site_id'		=> $this->params['site_id']
			)
	
		));

		// Graph params
		$this->params['graph_title'] = "Feed Formats for \n" . $this->get_period_label($this->params['period']);
		$this->params['legends'] = $result['feed_format'];
		$this->params['height']	= 400;
		$this->params['width']	= 400;
		$this->params['slice_label'] = 'fetches';
		
		// Graph Data Assignment
		if (empty($result)):
				$this->error_graph();
			return;
		else:
		//print_r($result);
			$this->data = array(
		
				'data_pie'		=> $result['count']
			
			);
	
			//print_r($this->data);
			$this->pie_graph();
		endif;
				
		return;
	}
	
}

?>
