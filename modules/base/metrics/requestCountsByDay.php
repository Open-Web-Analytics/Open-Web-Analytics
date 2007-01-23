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
 * Dashboard Core metrics By Day
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_requestCountsByDay extends owa_metric {
	
	function owa_requestCountsByDay($params = null) {
		
		$this->params = $params;
		
		$this->owa_metric();
		
		return;
		
	}
	
	function generate() {
		
		$this->params['select'] = "request.month, request.day, request.year, 
			count(distinct request.visitor_id) as unique_visitors, 
			count(distinct request.session_id) as sessions, 
			count(request.id) as page_views ";
		
		// $this->params['use_summary'] = true;
		
		$this->params['orderby'] = array('year', 'month', 'day');
		
		$this->setTimePeriod($this->params['period']);
		
		$r = owa_coreAPI::entityFactory('base.request');
		
		return $r->query($this->params);
		
		/*
		 
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
		 
		 
		 
		 */
		
	}
	
	
}


?>