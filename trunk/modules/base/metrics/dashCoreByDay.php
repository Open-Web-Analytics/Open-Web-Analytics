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

class owa_dashCoreByDay extends owa_metric {

	//var $limit = 10;
	
	function owa_dashCoreByDay($params = null) {
		
		return owa_dashCoreByDay::__construct($params);
	
	}
	
	function __construct($params = null) {
	
		parent::__construct($params);
		
		$this->setLabels(array('Month', 'Day', 'Year', 'Sessions', ' New Visitors', 'Repeat Visitors',  'Unique Visitors', 'Page Views', 'Pages/Visit'));
		
		return;
		
	}
	
	function calculate() {
		
		$this->db->selectFrom('owa_session', 'session');
		
		$this->db->selectColumn("session.month, 
								session.day, 
								session.year, 
								count(session.id) as sessions, 
								sum(session.is_new_visitor) as new_visitor, 
								sum(session.is_repeat_visitor) as repeat_visitor,
								count(distinct session.visitor_id) as unique_visitors, 
								sum(session.num_pageviews) as page_views,
								round((sum(session.num_pageviews) / count(session.id)), 1) as pages_per_visit");
									
		
		
		$this->db->groupBy('year');
		$this->db->groupBy('month');
		$this->db->groupBy('day');
			
		$this->db->orderBy('year', $this->getOrder());
		$this->db->orderBy('month', $this->getOrder());
		$this->db->orderBy('day', $this->getOrder());
		
		$ret = $this->db->getAllRows();
		
		//print($this->db->_last_sql_statement);
		
		return $ret;
				
	}
	
	function paginationCount() {
	
		$this->db->selectFrom('owa_session', 'session');
		
		$this->db->selectColumn("session.month, 
								session.day, 
								session.year, 
								count(session.id) as sessions");
								
		$this->db->groupBy('day');
		$this->db->groupBy('month');
		$this->db->groupBy('year');
		
		$ret = $this->db->getAllRows();
		
		return count($ret);
	
	}
	
	
}


?>