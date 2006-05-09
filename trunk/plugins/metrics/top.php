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

		$this->api_calls = array('top_documents', 'top_referers', 'top_user_agents', 'top_os', 'top_hosts', 'top_visitors');
		
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
			documents.url
		FROM 
			%s as requests, %s as documents 
		WHERE
			site_id = '%s' 
			AND %s
			%s AND documents.page_type != 'feed'
			AND requests.document_id = documents.id
		GROUP BY
			documents.page_title
		ORDER BY
			count DESC
		LIMIT 
			%s",
			$this->config['ns'].$this->config['requests_table'],
			$this->config['ns'].$this->config['documents_table'],
			$this->config['site_id'],
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
		
		$results = $this->db->get_results($sql);
		print mysql_error();
		return $results;
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
			is_searchengine
		FROM 
			%s as referers,
			%s as sessions 
		WHERE
			site_id = '%s' 
			AND %s
			AND referers.id != 0
			AND referers.id = sessions.referer_id
		GROUP BY
			referers.url
		ORDER BY
			count DESC
		LIMIT 
			%s",
			$this->config['ns'].$this->config['referers_table'],
			$this->config['ns'].$this->config['sessions_table'],
			$this->config['site_id'],
			$this->time_period($this->params['period']),
			$this->params['limit']
		);
		
		$results = $this->db->get_results($sql); 
		print mysql_error();
		return $results;
	
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
			site_id = '%s' 
			AND %s
		GROUP BY
			vis_id
		ORDER BY
			count DESC
		LIMIT 
			%s",
			$this->config['ns'].$this->config['sessions_table'],
			$this->config['site_id'],
			$this->time_period($this->params['period']),
			//$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
		
		return $this->db->get_results($sql);
	}
	
}

?>
