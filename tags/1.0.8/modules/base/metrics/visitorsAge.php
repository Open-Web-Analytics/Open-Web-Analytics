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
 * Visitors Age
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_visitorsAge extends owa_metric {
	
	function owa_visitorsAge($params = null) {
		
		$this->params = $params;
		
		$this->owa_metric();
		
		return;
		
	}
	
	function generate() {
		
		$s = owa_coreAPI::entityFactory('base.session');
		
		$v = owa_coreAPI::entityFactory('base.visitor');
		
		$this->params['related_objs'] = array('visitor_id' => $v);
		
		$this->setTimePeriod($this->params['period']);
		
		$this->params['select'] = "count(distinct session.visitor_id) as count,
									visitor.first_session_year,
									visitor.first_session_month,
									visitor.first_session_day,
									visitor.first_session_timestamp as timestamp";
								
		$this->params['groupby'] = array('visitor.first_session_year', 'visitor.first_session_month', 'visitor.first_session_day');
		
		$this->params['orderby'] = array('visitor.first_session_year', 'visitor.first_session_month', 'visitor.first_session_day');
	
		return $s->query($this->params);
		
	}
	
	
}


?>