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
 * Page View Metrics By Day
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_pageViewsTrend extends owa_metric {
	
	function owa_pageViewsTrend($params = null) {
		
		return owa_pageViewsTrend::__construct($params);
		
	}
	
	function __construct($params = null) {
	
		return parent::__construct($params);
	}
	
	function calculate() {
		
		$this->db->selectFrom('owa_session', 'session');
		$this->db->selectColumn("sum(session.num_pageviews) as count,
									session.month, 
									session.day, 
									session.year");
		
		if (array_key_exists('groupby', $this->params)):
			$this->db->groupBy($this->params['groupby']);
		else:
			$this->db->groupBy('session.day');
		endif;
		
		$this->db->orderBy('session.year');
		$this->db->orderBy('session.month');
		$this->db->orderBy('session.day');
		
		return $this->db->getAllRows();
		
	}
	
	
}


?>