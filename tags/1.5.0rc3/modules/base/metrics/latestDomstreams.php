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

class owa_latestDomstreams extends owa_metric {
	
	function owa_latestDomstreams($params = null) {
		
		return owa_latestDomstreams::__construct($params);
	}
	
	function __construct($params = null) {
		
		return parent::__construct($params);
	}
	
	function calculate() {
		
		$this->db->selectFrom('owa_domstream');
		$this->db->selectColumn("id, timestamp, page_url, duration");
		$this->db->selectColumn($this->setLabel('id', 'Domstream ID'));
		$this->db->selectColumn($this->setLabel('page_url', 'Page URL'));
		$this->db->selectColumn($this->setLabel('duration', 'Duration'));
		$this->db->selectColumn($this->setLabel('timestamp', 'Timestamp'));
		$this->db->orderBy('timestamp', 'DESC');
	}
	
	
}


?>