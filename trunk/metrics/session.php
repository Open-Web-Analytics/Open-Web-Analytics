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
 * Session Metrics
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    wa
 * @package     wa
 * @version		$Revision$	      
 * @since		wa 1.0.0
 */
class wa_metric_session extends wa_metric {

	/**
	 * Constructor
	 * 
	 * @access public
	 * @return wa_metric_session
	 */
	function wa_metric_session() {
		
		// Call parent constructor
		$this->wa_metric();
		
		$this->api_calls = array('session_detail');
		
		return;
	}
	
	/**
	 * Generate Metrics
	 *
	 * @access 	public
	 * @param 	array $params
	 * @return 	array
	 */
	function generate($params) {
	
		$this->params = $params;
	
		switch ($this->params['api_call']) {
		
		case "session_detail":
			return $this->session_detail();
			
		}
		
	}
		
	/**
	 * Generates click-stream detail of a visitor's session
	 *
	 * @access 	private
	 * @return 	array
	 */
	function session_detail() {
	
		$sql = sprintf("select 
			requests.month, 
			requests.day, 
			requests.year,
			requests.hour,
			requests.minute,
			requests.second,
			requests.timestamp,
			requests.visitor_id, 
			requests.session_id, 
			documents.page_title,
			requests.is_new_visitor,
			requests.is_entry_page,
			documents.url as page_uri,
			documents.page_type
		FROM 
			%s as requests,
			%s as documents
		WHERE
			site_id = %s 
			%s
			AND requests.document_id = documents.id
		ORDER BY
			timestamp ASC
		LIMIT 
			%s",
			$this->config['ns'].$this->config['requests_table'],
			$this->config['ns'].$this->config['documents_table'],
			$this->config['site_id'],
			$this->add_constraints($this->params['constraints']),
			$this->params['limit']
		);
		
		return $this->db->get_results($sql);
	}
	
}

?>
