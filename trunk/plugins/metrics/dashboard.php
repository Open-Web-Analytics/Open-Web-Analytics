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
 * Core Dashboard Metrics
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_metric_dashboard extends owa_metric {

	function owa_metric_dashboard() {

		$this->owa_metric();

		$this->api_calls = array('dash_core', 'page_views', 'page_view_count', 'dash_counts');
		
		return;
	}

	/**
	 * Generate Metrics
	 *
	 * @param 	array $params
	 * @access 	public
	 * @return 	array
	 */
	function generate($params) {
	
		$this->params = $params;
	
		switch ($this->params['api_call']) {
		
		case "dash_core":
			return $this->dash_core($params);
		case "page_views":
			return $this->page_views($params);
		case "page_view_count":
			return $this->page_view_count($params);
		case "dash_counts":
			return $this->dash_counts($params);
			
		}
	
	}

	/**
	 * Dashboard metrics
	 *
	 * @param 	array $params
	 * @access 	private
	 * @return 	array
	 */
	function dash_core($params) {
	
	$sql = sprintf("select 
			sessions.month, 
			sessions.day, 
			sessions.year, 
			count(distinct sessions.visitor_id) as unique_visitors, 
			count(sessions.session_id) as sessions, 
			sum(sessions.num_pageviews) as page_views 
		from 
			%s as sessions
		where 
			true
			%s 
			%s
		group by 
			sessions.%s
		ORDER BY
			sessions.year, 
			sessions.month, 
			sessions.day %s",
			$this->config['ns'].$this->config['sessions_table'],
			$this->time_period($params['period']),
			$this->add_constraints($params['constraints']),
			$params['group_by'],
			$params['order']
		);
	
		return $this->db->get_results($sql);		
	}
	
	/**
	 * Counts of core metrics
	 *
	 * @param 	array $params
	 * @access 	private
	 * @return 	array
	 */
	function dash_counts($params) {
	
		$sql = sprintf("select 
			count(distinct sessions.visitor_id) as unique_visitors, 
			sum(sessions.is_new_visitor) as new_visitor,
			count(sessions.session_id) as sessions, 
			sum(sessions.num_pageviews) as page_views 
		from 
			%s as sessions
		where 
			true
			%s 
			%s",
			$this->config['ns'].$this->config['sessions_table'],
			$this->time_period($params['period']),
			$this->add_constraints($params['constraints'])
		);
	
		return $this->db->get_row($sql);
	}
	
	/**
	 * Page Views over time
	 *
	 * @param 	array $params
	 * @access 	private
	 * @return 	array
	 */
	function page_views($params) {
	
		$sql = sprintf("select 
				sum(sessions.num_pageviews) as page_views,
				sessions.month, 
				sessions.day, 
				sessions.year
			from
				%s as sessions
			where
				%s 
				%s
			group by 
				sessions.%s
			ORDER BY
				sessions.year %6\$s, 
				sessions.month %6\$s, 
				sessions.day %6\$s",
				
				$this->config['ns'].$this->config['sessions_table'],
				$this->time_period($params['period']),
				$this->add_constraints($params['constraints']),
				$params['group_by'],
				$params['order']
			);
						
		
		return $this->db->get_results($sql);

	}
	
	/**
	 * Page view count
	 *
	 * @param 	array $params
	 * @access 	private
	 * @return 	array
	 */
	function page_view_count($params) {
	
		$sql = sprintf("select 
			sum(sessions.num_pageviews) as page_views			
		from
			%s as sessions
		where
			true
			%s 
			%s
		",
			$this->config['ns'].$this->config['sessions_table'],
			$this->time_period($params['period']),
			$this->add_constraints($params['constraints'])
		);
					
		return $this->db->get_row($sql);
	}
	
}

?>
