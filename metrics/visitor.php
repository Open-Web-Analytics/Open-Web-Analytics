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
 * @category    wa
 * @package     wa
 * @version		$Revision$	      
 * @since		wa 1.0.0
 */
class wa_metric_visitor extends wa_metric {

	/**
	 * Constructor
	 *
	 * @access public
	 * @return wa_metric_visitor
	 */
	function wa_metric_visitor() {

		$this->wa_metric();

		$this->api_calls = array('visitor_history', 'new_v_repeat', 'latest_visits');

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
		}
		
	}
		
	/*function visitor_history() {
	
		$sql = sprintf("select 
			wa_sessions.month, 
			wa_sessions.day, 
			wa_sessions.year,
			wa_sessions.hour,
			wa_sessions.minute,
			timestamp,
			visitor_id, 
			session_id, 
			referer,
			first_page_title,
			first_page_id,
			first_page_uri,
			first_page_type,
			ip_address,
			num_pageviews,
			is_new_visitor,
			num_comments,
			time_sinse_priorsession,
			prior_session_id,
			user_email,
			user_name,
			host 
		FROM 
			wa_sessions 
		WHERE
			site_id = %s
			AND %s 
			%s
		ORDER BY
			timestamp DESC
		LIMIT 
			%s",
			
			$this->config['site_id'],
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
	
	
		return $this->db->get_results($sql);
	
	}
	*/
	
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
			referers.site_name,
			referers.page_title,
			documents.page_title,
			documents.id,
			documents.page_title as first_page_title,
			documents.url as first_page_uri,
			documents.page_type as first_page_type
		FROM 
			%s as sessions,
			%s as referers,
			%s as documents
		WHERE
			site_id = %s
			AND %s 
			%s
			AND sessions.first_page_id = documents.id
			AND sessions.referer_id = referers.id
		ORDER BY
			sessions.timestamp DESC
		LIMIT 
			%s",
			$this->config['ns'].$this->config['sessions_table'],
			$this->config['ns'].$this->config['referers_table'],
			$this->config['ns'].$this->config['documents_table'],
			$this->config['site_id'],
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
			site_id = %s
			AND %s 
			%s",
			
			$this->config['ns'].$this->config['sessions_table'],
			$this->config['site_id'],
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints'])
		);

		return $this->db->get_results($sql);	
	}
	
}

?>