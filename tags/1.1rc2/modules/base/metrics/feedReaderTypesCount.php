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
 * Feed Reader Types Count
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_feedReaderTypesCount extends owa_metric {
	
	function owa_feedReaderTypesCount($params = null) {
		
		$this->params = $params;
		
		$this->owa_metric();
		
		return;
		
	}
	
	function generate() {
		
		$this->params['select'] = "count(distinct feed_request.feed_reader_guid) as count,
									ua.ua as ua,
									ua.browser_type";
		
		$this->setTimePeriod($this->params['period']);
		
		$f = owa_coreAPI::entityFactory('base.feed_request');
		
		$u = owa_coreAPI::entityFactory('base.ua');
		
		$this->params['related_objs'] = array('ua_id' => $u);
		
		$this->params['groupby'] = array('ua.browser_type');
		$this->params['orderby'] = array('count');
		$this->params['order'] = 'desc';
		return $f->query($this->params);
		
	}
	
	
}


?>