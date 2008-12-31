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

class owa_latestVisits extends owa_metric {
	
	function owa_latestVisits($params) {
	
		return parent::__construct($params);
		
	}
	
	function __construct($params) {
	
		return parent::__construct($params);
	}
	
	function calculate() {
			
		$s = owa_coreAPI::entityFactory('base.session');
		$h = owa_coreAPI::entityFactory('base.host');
		$ua = owa_coreAPI::entityFactory('base.ua');
		$d = owa_coreAPI::entityFactory('base.document');
		$v = owa_coreAPI::entityFactory('base.visitor');
		$r = owa_coreAPI::entityFactory('base.referer');
		
		$this->db->selectFrom($s->getTableName());
		
		$this->db->selectColumn($s->getColumnsSql('session_'));
		$this->db->selectColumn($h->getColumnsSql('host_'));
		$this->db->selectColumn($ua->getColumnsSql('ua_'));
		$this->db->selectColumn($d->getColumnsSql('document_'));
		$this->db->selectColumn($v->getColumnsSql('visitor_'));
		$this->db->selectColumn($r->getColumnsSql('referer_'));
		
		$this->db->join(OWA_SQL_JOIN_LEFT_OUTER, $h->getTableName(), '', 'host_id');
		$this->db->join(OWA_SQL_JOIN_LEFT_OUTER, $ua->getTableName(), '', 'ua_id');
		$this->db->join(OWA_SQL_JOIN_LEFT_OUTER, $d->getTableName(), '', 'first_page_id');
		$this->db->join(OWA_SQL_JOIN_LEFT_OUTER, $v->getTableName(), '', 'visitor_id');
		$this->db->join(OWA_SQL_JOIN_LEFT_OUTER, $r->getTableName(), '', 'referer_id');
			
		$this->db->orderBy('session_timestamp', $this->getOrder());
		
		$ret = $this->db->getAllRows();
		
		return $ret;
	}
	
	function paginationCount() {
	
		$this->db->selectFrom('owa_session');
		$this->db->selectColumn('count(id) as count');
		
		$ret = $this->db->getOneRow();
		
		return $ret['count'];
		
		
	}
	
	
}


?>