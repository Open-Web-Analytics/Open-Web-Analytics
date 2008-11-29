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
 * Top Hosts Metric
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_topHosts extends owa_metric {
	
	function owa_topHosts($params = array()) {
		
		return owa_topHosts::__construct($params);
		
	}
	
	function __construct($params = array()) {
	
		return parent::__construct($params);
	}
	
	function calculate() {
				
		$this->db->selectFrom('owa_session', 'session');
		$this->db->selectColumn("count(session.host_id) as count,
									host.id,
									host.host,
									host.full_host,
									host.ip_address");
		

		$this->db->join(OWA_SQL_JOIN_LEFT_OUTER, 'owa_host', 'host', 'host_id', 'host.id');
		$this->db->groupBy('host.id');
		$this->db->orderBy('count');
		$this->db->order('DESC');
		
		return $this->db->getAllRows();

	}
	
	function paginationCount() {
	
		$this->db->selectFrom('owa_session', 'session');
		$this->db->selectColumn("count(distinct session.host_id) as count");
		
		$ret = $this->db->getOneRow();
		return $ret['count'];
		
	
	}	
	
}


?>