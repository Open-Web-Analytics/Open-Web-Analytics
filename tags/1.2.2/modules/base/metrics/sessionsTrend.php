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
 * Sessions Trend
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_sessionsTrend extends owa_metric {
	
	function owa_sessionsTrend($params = null) {
		
		return owa_sessionsTrend::__construct($params = null);
		
	}
	
	function __construct($params = null) {
	
		parent::__construct($params);
		//print_r($this->params);
		return;
	
	}
	
	function calculate() {
		
		$this->db->selectFrom('owa_session', 'session');
		$this->db->selectColumn("count(session.id) as count, session.month, session.day, session.year");
		$this->db->groupBy("session.day");
		$this->db->groupBy("session.month");
		$this->db->groupBy("session.year");
		
		$this->db->orderBy("session.year, session.month, session.day");

		$ret = $this->db->getAllRows();
	
		return $ret;

	}
	
	
}


?>