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

require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'owa_metric.php');

/**
 * Session Browser Types Metric
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_sessionBrowserTypes extends owa_metric {
	
	function owa_sessionBrowserTypes($params = null) {
		
		$this->params = $params;
		
		$this->owa_metric();
		
		return;
		
	}
	
	function generate() {
		
		$s = owa_coreAPI::entityFactory('base.session');
		
		$ua = owa_coreAPI::entityFactory('base.ua');
		
		$this->params['related_objs'] = array('ua_id' => $ua);
		
		$this->setTimePeriod($this->params['period']);
		
		$this->params['select'] = "count(distinct session.id) as count,
									ua.ua as ua,
									ua.browser_type";
								
		$this->params['groupby'] = array('ua.browser_type');
		
		$this->params['orderby'] = array('count');
	
		return $s->query($this->params);
		
		/*
		$sql = sprintf("
		SELECT 
			count(distinct sessions.session_id) as count,
			ua.ua as ua,
			ua.browser_type
		FROM 
			%s as sessions,
			%s as ua
		WHERE
			ua.id = sessions.ua_id
			%s 
			%s
		GROUP BY
			ua.browser_type
		ORDER BY
			count DESC
		",
			$this->setTable($this->config['sessions_table']),
			$this->setTable($this->config['ua_table']),
			$this->time_period($this->params['period']),
			$this->add_constraints($this->params['constraints'])
		);
	
		return $this->db->get_results($sql);
		
		*/
		
		
	}
	
	
}


?>