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
 * Feed Formats Count
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_feedFormatsCount extends owa_metric {
	
	function owa_feedFormatsCount($params = null) {
				
		return owa_feedFormatsCount::__construct($params);
		
	}
	
	function __construct($params = null) {
		
		return parent::__construct($params);
	}
	
	function generate() {
		
		$this->db->selectColumn("count(id) as count, feed_format");
		$this->db->selectFrom("owa_feed_requests");
		$this->db->groupBy('feed_format');
		
		return $this->db->getAllRows();
		
	}
	
	function paginationCount() {
	
		$this->db->selectColumn("count(id) as count");
		$this->db->selectFrom("owa_feed_requests");
		
		$ret = $this->db->getOneRow();
		return $ret['count'];
	}
	
	
}


?>