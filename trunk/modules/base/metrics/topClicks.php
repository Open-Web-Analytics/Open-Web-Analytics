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
 * Top Clicks Metric
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_topClicks extends owa_metric {
	
	function owa_topClicks($params = null) {
		
		return owa_topClicks::__construct($params);
	}
	
	function __construct($params= null) {
		
		return parent::__construct($params);
	}
	
	function calculate() {
		
		$this->db->selectFrom('owa_click');
		$this->db->selectColumn("	click_x as x,
									click_y as y,
									page_width,
									page_height,
									dom_element_x,
									dom_element_y,
									position");
		
		
		$this->db->orderBy('position', 'DESC');
	}
	
	
}


?>