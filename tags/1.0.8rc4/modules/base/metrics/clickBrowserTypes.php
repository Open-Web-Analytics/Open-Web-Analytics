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
 * Click Browser Types Metric
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_clickBrowserTypes extends owa_metric {
	
	function owa_clickBrowserTypes($params = null) {
		
		$this->params = $params;
		
		$this->owa_metric();
		
		return;
		
	}
	
	function generate() {
		
		$c = owa_coreAPI::entityFactory('base.click');
		
		$ua = owa_coreAPI::entityFactory('base.ua');
		
		$this->params['related_objs'] = array('ua_id' => $ua);
		
		$this->setTimePeriod($this->params['period']);
		
		$this->params['select'] = "count(distinct click.id) as count,
									ua.id,
									ua.ua as ua,
									ua.browser_type";
								
		$this->params['groupby'] = array('ua.browser_type');
		
		$this->params['orderby'] = array('count');
	
		return $c->query($this->params);
		
	}
	
	
}


?>