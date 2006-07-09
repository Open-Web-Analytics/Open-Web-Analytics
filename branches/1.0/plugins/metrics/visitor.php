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
 * Visitor Metrics
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_metric_visitor extends owa_metric {

	/**
	 * Constructor
	 *
	 * @access public
	 * @return owa_metric_visitor
	 */
	function owa_metric_visitor() {

		$this->owa_metric();

		$this->api_calls = array('visitor_list', 'visitors_age', 'visitor_history', 'new_v_repeat', 'latest_visits');

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
		
		case "new_v_repeat":
			return $this->new_v_repeat();
		case "latest_visits":
			return $this->latest_visits();		
		case "visitors_age":
			return $this->visitors_age();
		case "visitor_list":
			return $this->visitor_list();			
		}
		
	}
		
	
	/**
	 * Generates Visits
	 *
	 * @access private
	 * @return array
	 */
	function latest_visits() {
	
		$sql = sprintf("
		SELECT 
			sessions.month, 
			sessions.day, 
			sessions.year,
			sessions.hour,
			sessions.minute,
			sessions.timestamp,
			sessions.visitor_id, 
			sessions.session_id, 
			sessions.referer_id,
			sessions.first_page_id,
			sessions.ip_address,
			sessions.num_pageviews,
			sessions.is_new_visitor,
			sessions.num_comments,
			sessions.time_sinse_priorsession,
			sessions.prior_session_id,
			sessions.user_email,
			sessions.user_name,
			sessions.host,
			sessions.city,
			sessions.country,
			referers.url as referer,
			referers.site_name as referrer_site_name,
			referers.page_title as referer_page_title,
			referers.snippet as referer_snippet,
			referers.refering_anchortext as referer_anchortext,
			documents.page_title,
			documents.id,
			documents.page_title as first_page_title,
			documents.url as first_page_uri,
			documents.page_type as first_page_type,
			ua.ua,
			ua.browser_type
		FROM 
			%s as sessions,
			%s as referers,
			%s as documents,
			%s as ua
		WHERE
			sessions.first_page_id = documents.id
			AND sessions.referer_id = referers.id
			AND ua.id = sessions.ua_id
			%s 
			%s
		ORDER BY
			sessions.timestamp DESC
		LIMIT 
			%s",
			$this->setTable($this->config['sessions_table']),
			$this->setTable($this->config['referers_table']),
			$this->setTable($this->config['documents_table']),
			$this->setTable($this->config['ua_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
	
		return $this->db->get_results($sql);
	}
	
	/**
	 * Generates new versus repeat visitor counts
	 *
	 * @access private
	 * @return array
	 */
	function new_v_repeat() {
	
		$sql = sprintf("
		SELECT 
			sum(is_new_visitor) as new_visitor,
			sum(is_repeat_visitor) as repeat_visitor
		FROM 
			%s 
		WHERE
			true
			%s 
			%s",
			
			$this->setTable($this->config['sessions_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints'])
		);

		
		return $this->db->get_row($sql);	
	}
	
	/**
	 * Generates a count of visitors by the month that they 
	 * first visited
	 *
	 * @access private
	 * @return array
	 */
	function visitors_age() {
		
		$sql = sprintf("
		SELECT
			count(sessions.visitor_id) as count,
			visitors.first_session_year,
			visitors.first_session_month 
		FROM 
			%s as sessions, %s as visitors 
		WHERE
			sessions.visitor_id = visitors.visitor_id
			%s
			%s
		GROUP BY
			visitors.first_session_year,
			visitors.first_session_month
		ORDER BY
			visitors.first_session_year,
			visitors.first_session_month DESC",
			
			$this->setTable($this->config['sessions_table']),
			$this->setTable($this->config['visitors_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints'])
		);

		return $this->db->get_results($sql);	
		
	}
	
	/**
	 * Visitor List
	 *
	 * @access 	private
	 * @return 	array
	 */
	function visitor_list() {
	
		$sql = sprintf("
		SELECT 
			distinct sessions.visitor_id as visitor_id,
			visitors.user_name,
			visitors.user_email
		FROM 
			%s as sessions,
			%s as visitors 
		WHERE
			sessions.visitor_id = visitors.visitor_id 
			%s
			%s
		LIMIT 
			%s",
			$this->setTable($this->config['sessions_table']),
			$this->setTable($this->config['visitors_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
		
		return $this->db->get_results($sql);
	}
	
}

?>