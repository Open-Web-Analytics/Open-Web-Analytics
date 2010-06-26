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
 * Top Refering Keywords Metric
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_topReferingKeywords extends owa_metric {
	
	function owa_topReferingKeywords($params = null) {
				
		return owa_topReferingKeywords::__construct($params);
		
	}
	
	function __construct($params) {
	
		return parent::__construct($params);
	}
	
	function calculate() {
		
	
		$this->db->selectColumn("count(session.id) as count, referer.query_terms");						
		$this->db->selectFrom('owa_session', 'session');
		$this->db->join(OWA_SQL_JOIN_LEFT_OUTER, 'owa_referer', 'referer', 'referer_id', 'referer.id');		
		$this->db->groupBy('referer.query_terms');		
		$this->db->orderBy('count', $this->getOrder());	
		$this->db->where('referer.id', 0, '!=');
		$this->db->where('referer.query_terms', ' ', '!=');		
		
		$ret = $this->db->getAllRows();

		return $ret;

	}
	
	function paginationCount() {
	
		$this->db->selectColumn("count(distinct referer.query_terms) as count");						
		$this->db->selectFrom('owa_session', 'session');
		$this->db->join(OWA_SQL_JOIN_LEFT_OUTER, 'owa_referer', 'referer', 'referer_id', 'referer.id');		
		$this->db->where('referer.id', 0, '!=');
		$this->db->where('referer.query_terms', ' ', '!=');		
		
		$ret = $this->db->getOneRow();

		return $ret['count'];

	}
	
	
}


?>