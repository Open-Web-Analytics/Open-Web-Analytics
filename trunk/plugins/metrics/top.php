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
 * Top Metrics
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_metric_top extends owa_metric {

	/**
	 * Constructor
	 *
	 * @access 	public
	 * @return 	owa_metric_top
	 */
	function owa_metric_top() {

		$this->owa_metric();

		$this->api_calls = array('top_anchors', 
								'top_keywords', 
								'top_documents', 
								'top_entry_documents', 
								'top_exit_documents', 
								'top_referers', 
								'top_user_agents', 
								'top_os', 
								'top_hosts', 
								'top_visitors');
		
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
		
		case "top_documents":
			return $this->top_documents();
		case "top_referers":
			return $this->top_referers();
		case "top_visitors":
			return $this->top_visitors();
		case "top_entry_documents":
			return $this->top_entry_documents();
		case "top_exit_documents":
			return $this->top_exit_documents();
		case "top_keywords":
			return $this->top_keywords();
		case "top_anchors":
			return $this->top_anchors();
		}
		
	}

	/**
	 * Top Documents
	 *
	 * @access 	private
	 * @return 	array
	 */
	function top_documents() {
	
		$sql = sprintf("
		SELECT 
			count(requests.document_id) as count,
			documents.page_title,
			documents.page_type,
			documents.url,
			documents.id
		FROM 
			%s as requests, %s as documents 
		WHERE
			documents.page_type != 'feed'
			AND requests.document_id = documents.id
			%s
			%s 
		GROUP BY
			documents.id
		ORDER BY
			count DESC
		LIMIT 
			%s",
			$this->setTable($this->config['requests_table']),
			$this->setTable($this->config['documents_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
		
		return $this->db->get_results($sql);
	
		
	}
	
	/**
	 * Top Entry Documents
	 *
	 * @access 	private
	 * @return 	array
	 */
	function top_entry_documents() {
	
		$sql = sprintf("
		SELECT 
			count(sessions.session_id) as count,
			documents.page_title,
			documents.page_type,
			documents.url,
			documents.id
		FROM 
			%s as sessions, %s as documents 
		WHERE
			sessions.first_page_id = documents.id
			%s
			%s 
		GROUP BY
			sessions.first_page_id
		ORDER BY
			count DESC
		LIMIT 
			%s",
			$this->setTable($this->config['sessions_table']),
			$this->setTable($this->config['documents_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
		
		return $this->db->get_results($sql);
	
		
	}
	
	/**
	 * Top Entry Documents
	 *
	 * @access 	private
	 * @return 	array
	 */
	function top_exit_documents() {
	
		$sql = sprintf("
		SELECT 
			count(sessions.session_id) as count,
			documents.page_title,
			documents.page_type,
			documents.url,
			documents.id
		FROM 
			%s as sessions, %s as documents 
		WHERE
			sessions.last_page_id = documents.id
			%s
			%s 
		GROUP BY
			sessions.last_page_id
		ORDER BY
			count DESC
		LIMIT 
			%s",
			$this->setTable($this->config['sessions_table']),
			$this->setTable($this->config['documents_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
		
		return $this->db->get_results($sql);
	
		
	}
	
	/**
	 * Top Referers
	 *
	 * @access 	private
	 * @return 	array
	 */
	function top_referers() {
	
		$sql = sprintf("
		SELECT 
			count(referers.id) as count,
			url,
			page_title,
			site_name,
			query_terms,
			snippet,
			refering_anchortext,
			is_searchengine
		FROM 
			%s as referers,
			%s as sessions 
		WHERE 
			referers.id != 0
			AND referers.id = sessions.referer_id
			%s
			%s
		GROUP BY
			referers.url
		ORDER BY
			count DESC
		LIMIT 
			%s",
			$this->setTable($this->config['referers_table']),
			$this->setTable($this->config['sessions_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
		
		return $this->db->get_results($sql); 
	
	}
	
	/**
	 * Top Keywords
	 *
	 * @access 	private
	 * @return 	array
	 */
	function top_keywords() {
	
		$sql = sprintf("
		SELECT 
			count(sessions.session_id) as count,
			referers.query_terms
		FROM 
			%s as referers,
			%s as sessions 
		WHERE 
			referers.id != 0
			and query_terms != ''
			AND referers.id = sessions.referer_id
			%s
			%s
		GROUP BY
			referers.query_terms
		ORDER BY
			count DESC
		LIMIT 
			%s",
			$this->setTable($this->config['referers_table']),
			$this->setTable($this->config['sessions_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
		
		return $this->db->get_results($sql); 
	
	}
	
	/**
	 * Top Anchors
	 *
	 * @access 	private
	 * @return 	array
	 */
	function top_anchors() {
	
		$sql = sprintf("
		SELECT 
			count(sessions.session_id) as count,
			referers.refering_anchortext
		FROM 
			%s as referers,
			%s as sessions 
		WHERE 
			referers.id != 0
			AND refering_anchortext != ''
			AND referers.is_searchengine = '0'
			AND referers.id = sessions.referer_id
			%s
			%s
		GROUP BY
			referers.refering_anchortext
		ORDER BY
			count DESC
		LIMIT 
			%s",
			$this->setTable($this->config['referers_table']),
			$this->setTable($this->config['sessions_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
		
		return $this->db->get_results($sql); 
	
	}
	
	
	/**
	 * Top Visitors
	 *
	 * @access 	private
	 * @return 	array
	 */
	function top_visitors() {
	
		$sql = sprintf("
		SELECT 
			count(visitor_id) as count,
			visitor_id as vis_id,
			user_name,
			user_email
		FROM 
			%s
		WHERE
			true
			%s
			%s
		GROUP BY
			vis_id
		ORDER BY
			count DESC
		LIMIT 
			%s",
			$this->setTable($this->config['sessions_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
		
		return $this->db->get_results($sql);
	}
	
}

?>
