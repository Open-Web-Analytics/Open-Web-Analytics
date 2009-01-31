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
		
		return owa_sessionBrowserTypes::__construct($params);
		
	}
	
	function __construct($params = null) {
	
		parent::__construct($params);
	}
	
	function calculate() {
					
		$this->db->selectFrom('owa_session', 'session');
		$this->db->selectColumn("count(distinct session.id) as count, ua.ua as ua, ua.browser_type");
		$this->db->join(OWA_SQL_JOIN_LEFT_OUTER, 'owa_ua', 'ua', 'ua_id', 'ua.id');
		$this->db->groupBy('ua.browser_type');
		$this->db->orderBy('count', $this->getOrder());
		
		return $this->db->getAllRows();

	}
	
	
}


?>