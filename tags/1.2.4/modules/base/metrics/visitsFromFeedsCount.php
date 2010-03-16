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
 * Visits From Feeds Count Metric
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_visitsFromFeedsCount extends owa_metric {
	
	function owa_visitsFromFeedsCount($params = null) {
		
		return owa_visitsFromFeedsCount::__construct($params);
		
	}
	
	function __construct($params = null) {
		
		return parent::__construct($params);
	}
	
	function calculate() {
		
		$this->db->selectColumn("count(session.id) as count");
		$this->db->selectFrom('owa_session', 'session');
		$this->db->join(OWA_SQL_JOIN_LEFT_OUTER, 'owa_referer', '', 'referer_id');
		$this->db->where('session.source', 'feed');
		$this->db->where('owa_referer.is_searchengine', 1, '!=');
				
		$ret = $this->db->getOneRow();
		
		if (empty($ret)):
			$ret = array('count' => 0);
		endif;
		
		return $ret;
		
	}
	
	
}


?>