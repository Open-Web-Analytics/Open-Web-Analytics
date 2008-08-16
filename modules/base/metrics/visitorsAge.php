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
 * Visitors Age
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_visitorsAge extends owa_metric {
	
	function owa_visitorsAge($params = null) {
		
		$this->params = $params;
		
		$this->owa_metric();
		
		return;
		
	}
	
	function calculate() {
		
		$db = owa_coreAPI::dbSingleton();
		$db->selectColumn("count(distinct session.visitor_id) as count,
									visitor.first_session_year,
									visitor.first_session_month,
									visitor.first_session_day,
									visitor.first_session_timestamp as timestamp");
									
		$db->selectFrom('owa_session', 'session');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, 'owa_visitor', 'visitor', 'visitor_id', 'visitor.id');
		// pass constraints set by caller into where clause
		$db->multiWhere($this->getConstraints());
		
		$db->groupBy('visitor.first_session_year');
		$db->groupBy('visitor.first_session_month');
		$db->groupBy('visitor.first_session_day');
		
		$db->orderBy('visitor.first_session_year');
		$db->orderBy('visitor.first_session_month');
		$db->orderBy('visitor.first_session_day');
		
		$ret = $db->getAllRows();

		return $ret;
			
	}
	
	
}


?>