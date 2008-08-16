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
		
		$this->params = $params;
		
		$this->owa_metric();
		
		return;
		
	}
	
	function calculate() {
		
		$db = owa_coreAPI::dbSingleton();
		$db->selectColumn("count(session.id) as source_count");
		$db->selectFrom('base.session');
		$db->join(OWA_SQL_JOIN_LEFT_OUTER, 'base.referer', '', 'referer_id');
		$db->where('session.source', 'feed');
		$db->where('referer.is_searchengine', 1, '!=');
		
		// pass constraints set by caller into where clause
		$db->multiWhere($this->getConstraints());
		
		$ret = $db->getOneRow();
		
		return $ret;
		
	}
	
	
}


?>