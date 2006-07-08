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
 * Document Metrics
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_metric_documents extends owa_metric {

	/**
	 * Constructor
	 *
	 * @access public
	 * @return owa_metric_visitor
	 */
	function owa_metric_documents() {

		$this->owa_metric();

		$this->api_calls = array('count_page_types', 'document_details', 'count_document_metrics', 'document_core_metrics');

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
		
		case "count_page_types":
			return $this->count_page_types();
		case "document_core_metrics":
			return $this->document_core_metrics();
		case "count_document_metrics":
			return $this->count_document_metrics();
		case "document_details":
			return $this->document_details();
		}
		
	}
		
	
	/**
	 * Generates count of documents requested by Page Type
	 *
	 * @access private
	 * @return array
	 */
	function count_page_types() {
	
		$sql = sprintf("
		SELECT 
			count(requests.request_id) as count,
			documents.page_title,
			documents.page_type,
			documents.url,
			documents.id
		FROM 
			%s as requests, %s as documents 
		WHERE
			requests.document_id = documents.id
			%s
			%s 
		GROUP BY
			page_type
		ORDER BY
			count DESC
			",
			$this->setTable($this->config['requests_table']),
			$this->setTable($this->config['documents_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
		
		return $this->db->get_results($sql);
	}
	
	/**
	 * Fetches a set of core metrics that relate to a document.
	 *
	 * @access private
	 * @return array
	 */
	function document_core_metrics() {
		
		$sql = sprintf("select 
			requests.month, 
			requests.day, 
			requests.year, 
			count(distinct requests.visitor_id) as unique_visitors, 
			count(distinct requests.session_id) as sessions, 
			count(requests.request_id) as page_views 
		from 
			%s as requests
		where 
			true
			%s 
			%s
		group by 
			requests.%s
		ORDER BY
			requests.year, 
			requests.month, 
			requests.day %s",
			$this->setTable($this->config['requests_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints']),
			$this->params['group_by'],
			$this->params['order']
		);
	
		return $this->db->get_results($sql);		
	}
	
	/**
	 * Counts of document core metrics
	 *
	 * @param 	array $params
	 * @access 	private
	 * @return 	array
	 */
	function count_document_metrics() {
	
		$sql = sprintf("
		SELECT 
			count(distinct requests.visitor_id) as unique_visitors, 
			count(requests.session_id) as sessions, 
			count(requests.request_id) as page_views 
		FROM 
			%s as requests
		
		WHERE 
			true
			%s 
			%s
			",
			$this->setTable($this->config['requests_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints'])
		);
	
		return $this->db->get_row($sql);
	}
	
	/**
	 * Counts of document core metrics
	 *
	 * @param 	array $params
	 * @access 	private
	 * @return 	array
	 */
	function document_details() {
	
		$sql = sprintf("
		SELECT 
			documents.page_title,
			documents.url,
			documents.page_type
			 
		FROM 
			%s as documents
		WHERE 
			documents.id = '%s' 
			",
			$this->setTable($this->config['documents_table']),
			$this->params['document_id']
		);
	
		return $this->db->get_row($sql);
	}
	
}

?>