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

class owa_pageViewsCount extends owa_metric {
	
	function owa_pageViewsCount($params = null) {
		
		$this->params = $params;
		
		$this->owa_metric();
		
		return;
		
	}
	
	function calculate() {
		
		$db = owa_coreAPI::dbSingleton();
		
		$db->selectFrom('owa_session', 'session');
		$db->selectColumn("sum(session.num_pageviews) as page_views");
		// pass constraints into where clause
		$db->multiWhere($this->getConstraints());
		
		return $db->getOneRow();
	
	}
	
	function count() {
		$db = owa_coreAPI::dbSingleton();
		
		$db->selectFrom('owa_session', 'session');
		$db->selectColumn("sum(session.num_pageviews) as page_views");
		// pass constraints into where clause
		$db->multiWhere($this->getConstraints());
		$this->setLabels('Page Views');
		$res = $db->getOneRow();
		return $res['page_views'];
	}
	
	function trend() {
		
		$this->db->selectFrom('owa_session', 'session');
		$this->db->selectColumn("sum(session.num_pageviews) as count,
									session.month, 
									session.day, 
									session.year");
		
		if (array_key_exists('groupby', $this->params)):
			$this->db->groupBy($this->params['groupby']);
		else:
			$this->db->groupBy('session.day');
		endif;
		
		$this->db->orderBy('session.year');
		$this->db->orderBy('session.month');
		$this->db->orderBy('session.day');
		
		return $this->db->getAllRows();
	}
	
	
}


?>