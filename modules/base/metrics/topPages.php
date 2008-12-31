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
 * Top Web Pages Metric
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */

class owa_topPages extends owa_metric {
	
	function owa_topPages($params) {
	
		return $this->__construct($params);
		
	}
	
	function __construct($params) {
				
		$this->setLabels(array('Page Views', 'Page Name', 'Page Type', 'URL', 'Document ID'));
		return parent::__construct($params);
	}

	
	function calculate() {
						
		$r = owa_coreAPI::entityFactory('base.request');
		$d = owa_coreAPI::entityFactory('base.document');
		
		$this->db->selectFrom($r->getTableName(), 'request');
		$this->db->selectColumn("count(request.document_id) as count,
						document.page_title,
						document.page_type,
						document.url,
						document.id as document_id");
				
		$this->db->where('document.page_type', 'feed', '!=');
		$this->db->join(OWA_SQL_JOIN_LEFT_OUTER,$d->getTableName(), 'document', 'document_id', 'document.id');
		$this->db->groupBy('document.id');
		$this->db->orderBy('count', $this->getOrder());
				
		return $this->db->getAllRows();
		
	}
	
	function paginationCount() {
	
		$this->db->selectFrom('owa_request');
		$this->db->selectColumn("count(distinct document_id) as count");
		$this->db->where('is_browser', 1);
		$ret = $this->db->getOneRow();
		return $ret['count'];
		
	}
	
	
}


?>