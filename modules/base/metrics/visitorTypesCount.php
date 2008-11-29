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
 * New and Repeat user Counts
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_visitorTypesCount extends owa_metric {
	
	function __construct($params = array()) {
	
		return parent::__construct($params);
	}
	
	function owa_visitorTypesCount($params = array()) {
		
		return owa_visitorTypesCount::__construct($params);
	}
	
	function calculate() {
		
		$this->db->selectColumn("sum(is_new_visitor) as new_visitor, sum(is_repeat_visitor) as repeat_visitor");
		$this->db->selectFrom('owa_session');		
		$ret = $this->db->getOneRow();
		
		return $ret;
	}
	
	
}


?>