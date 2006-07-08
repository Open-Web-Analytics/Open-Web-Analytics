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
 * Feed Metrics
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_metric_feeds extends owa_metric {

	/**
	 * Constructor
	 *
	 * @access public
	 * @return owa_metric_visitor
	 */
	function owa_metric_feeds() {

		$this->owa_metric();

		$this->api_calls = array('feed_circulation', 
								'feed_fetches_trend', 
								'count_feed_fetches_by_format', 
								'count_unique_feed_readers', 
								'count_feed_readers_by_ua'
								);

	}
	
	/**
	 * Generates Metrics
	 *
	 * @param array $params
	 * @access public
	 * @return unknown
	 */
	function generate($params) {
	
		$this->params = $params;
	
		switch ($this->params['api_call']) {
		
		case "count_feed_fetches":
			return $this->count_feed_fetches();
		case "count_feed_fetches_by_format":
			return $this->count_feed_fetches_by_format();
		case "count_unique_feed_readers":
			return $this->count_unique_feed_readers();
		case "count_feed_readers_by_ua":
			return $this->count_feed_readers_by_ua();
		case "feed_circulation":
			return $this->feed_circulation();
		case "feed_fetches_trend":
			return $this->feed_fetches_trend();
		}
		
	}
		
	/**
	 * Trend of Feed Fetches
	 *
	 * @access private
	 * @return array
	 */
	function feed_fetches_trend() {
		
		$sql = sprintf("
		SELECT 
			count(request_id) as fetch_count,
			count(distinct feed_reader_guid) as reader_count,
			year,
			month,
			day
		FROM 
			%s as feed_requests
		WHERE
			true 
			%s 
			%s
		GROUP BY
			feed_requests.%s
		ORDER BY
			year,
			month,
			day %s
		",
			$this->setTable($this->config['feed_requests_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['group_by'],
			$this->params['order']
		);
	
		return $this->db->get_results($sql);
	}
	
	/**
	 * Number of Feed Fetches
	 *
	 * @access private
	 * @return array
	 */
	function count_feed_fetches() {
	
		$sql = sprintf("
		SELECT 
			count(request_id) as count
		FROM 
			%s as feed_requests
		WHERE
			true 
			%s 
			%s
		",
			$this->setTable($this->config['feed_requests_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints'])
		);
	
		return $this->db->get_row($sql);
	}
	
	/**
	 * Number of Unique Feed Readers
	 *
	 * @access private
	 * @return array
	 */
	function count_unique_feed_readers() {
	
		$sql = sprintf("
		SELECT 
			count(distinct feed_reader_guid) as count
		FROM 
			%s as feed_requests
		WHERE
			true 
			%s 
			%s
		",
			$this->setTable($this->config['feed_requests_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints'])
		);
	
		return $this->db->get_row($sql);
	}
	
	/**
	 * Number of Feed Fetches
	 *
	 * @access private
	 * @return array
	 */
	function count_feed_fetches_by_format() {
	
		$sql = sprintf("
		SELECT 
			count(request_id) as count, 
			feed_format
		FROM 
			%s as feed_requests
		WHERE
			true 
			%s 
			%s
		GROUP BY
			feed_format
		",
			$this->setTable($this->config['feed_requests_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints'])
		);
	
		return $this->db->get_results($sql);
	}
	
	/**
	 * Number of Feed Fetches
	 *
	 * @access private
	 * @return array
	 */
	function count_feed_readers_by_ua() {
	
		$sql = sprintf("
		SELECT 
			count(distinct feed_requests.feed_reader_guid) as count,
			ua.ua as ua,
			ua.browser_type
		FROM 
			%s as feed_requests,
			%s as ua
		WHERE
			ua.id = ua_id
			%s 
			%s
		GROUP BY
			ua.browser_type
		",
			$this->setTable($this->config['feed_requests_table']),
			$this->setTable($this->config['ua_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints'])
		);
	
		return $this->db->get_results($sql);
	}
	
}
?>
