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
 * Traffic Source Metrics
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_metric_source extends owa_metric {

	/**
	 * Constructor
	 * 
	 * @access public
	 * @return owa_metric_session
	 */
	function owa_metric_source() {
		
		// Call parent constructor
		$this->owa_metric();
		
		$this->api_calls = array('from_feed', 'from_search_engine', 'from_sites');
		
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
		
		case "from_feed":
			return $this->from_source('feed');
			
		case "from_search_engine":
			return $this->from_search_engine();
			
		case "from_sites":
			return $this->from_sites();
			
		}
		
	}
		
	/**
	 * Generates Count of visitors from a particular source
	 *
	 * @access 	private
	 * @return 	array
	 */
	function from_source($source) {
	
		$sql = sprintf("select 
			count(sessions.session_id) as source_count
		FROM 
			%s as sessions
		WHERE
			sessions.source = '%s'
			%s
			%s
			",
			$this->setTable($this->config['sessions_table']),
			$source,
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints'])
			
		);
		
		return $this->db->get_row($sql);
	}
	
	/**
	 * Generates Count of visitors from search engines
	 *
	 * @access 	private
	 * @return 	array
	 */
	function from_search_engine() {
	
		$sql = sprintf("select 
			count(sessions.session_id) as se_count
		FROM 
			%s as sessions,
			%s as referers
		WHERE
			sessions.referer_id = referers.id
			AND referers.is_searchengine = 1
			%s
			%s",
			$this->setTable($this->config['sessions_table']),
			$this->setTable($this->config['referers_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints'])
		);
		
		return $this->db->get_row($sql);
	}
	
	/**
	 * Generates Count of visitors from sites other than known search engines
	 *
	 * @access 	private
	 * @return 	array
	 */
	function from_sites() {
	
		$sql = sprintf("select 
			count(sessions.session_id) as site_count
		FROM 
			%s as sessions,
			%s as referers
		WHERE
			sessions.referer_id = referers.id
			AND referers.is_searchengine = 0
			%s
			%s",
			$this->setTable($this->config['sessions_table']),
			$this->setTable($this->config['referers_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints'])
		);
		
		return $this->db->get_row($sql);
	}
	
}

?>
