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
 * Top Visitors metric
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_topVisitors extends owa_metric {
	
	function owa_topVisitors($params = null) {
		
		$this->params = $params;
		
		$this->owa_metric();
		
		return;
		
	}
	
	function generate() {
		
		$s = owa_coreAPI::entityFactory('base.session');
		
		$this->setTimePeriod($this->params['period']);
		
		$this->params['select'] = "count(visitor_id) as count,
									visitor_id as vis_id,
									user_name,
									user_email";
								
		$this->params['groupby'] = array('vis_id');
		
		$this->params['orderby'] = array('count');
	
		return $s->query($this->params);
		
		/*
		
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
		
		*/
	}
	
	
}


?>