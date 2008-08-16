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
			
		$db = owa_coreAPI::dbSingleton();
		
		$s = owa_coreAPI::entityFactory('base.session');
		$h = owa_coreAPI::entityFactory('base.host');
		$ua = owa_coreAPI::entityFactory('base.ua');
		$d = owa_coreAPI::entityFactory('base.document');
		$v = owa_coreAPI::entityFactory('base.visitor');
		$r = owa_coreAPI::entityFactory('base.referer');
		
		$db->selectFrom($s->getTableName());
		
		$db->selectColumn($s->getColumnsSql('session_'));
		$db->selectColumn($h->getColumnsSql('host_'));
		$db->selectColumn($ua->getColumnsSql('ua_'));
		$db->selectColumn($d->getColumnsSql('document_'));
		$db->selectColumn($v->getColumnsSql('visitor_'));
		$db->selectColumn($r->getColumnsSql('referer_'));
		
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $h->getTableName(), '', 'host_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $ua->getTableName(), '', 'ua_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $d->getTableName(), '', 'first_page_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $v->getTableName(), '', 'visitor_id');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, $r->getTableName(), '', 'referer_id');
		
		// pass constraints into where clause
		$db->multiWhere($this->getConstraints());
		$db->orderBy('session_timestamp');
		$db->order($this->params['order']);
		$db->limit($this->params['limit']);
		$db->offset($this->params['offset']);
		
		$ret = $db->getAllRows();
		
		return $ret;
	}
	
	
}


?>