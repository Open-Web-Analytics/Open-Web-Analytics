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
 * Pagination
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_pagination extends owa_base {

	var $page = 1;
	
	var $limit;
	
	var $offset = 0;
	
	var $total_count;
	
	function __construct() {
		
		return;
	
	}
	
	function setLimit($limit) {
		$this->limit = $limit;
		return;
	}
	
	function setPage($page) {
		$this->page = $page;
		return;
	}
	
	function setMorePages($bool) {
		
		$this->more_pages = $bool;
		return;
	
	}
	
	function calculateOffset() {
		
		$this->offset = $this->limit * ($this->page - 1);
		return $this->offset;
	}
	
	function getMaxPageNum() {
		
		if ($this->total_count > 0) {
		
			$c = $this->total_count / $this->limit;
			$c = ceil($c);
		} else {
		
			$c = 0;
		}
		
		return $c;
	}
	
	function getPagination() {
		
		$pagination = array();
		$pagination['limit'] = $this->limit;
		$pagination['page_num'] = $this->page;
		$pagination['offset'] = $this->offset;
		$pagination['max_page_num'] = $this->getMaxPageNum();
		$pagination['more_pages'] = $this->more_pages;
		$pagination['total_count'] = $this->total_count;
		$pagination['results_count'] = $this->results_count;
		$pagination['diff_count'] = $this->total_count - $this->results_count;
		return $pagination;
	}
	
	function countResults($results) {
	
		$this->results_count = count($results);
		
		if ($this->results_count < $this->limit):
			$this->more_pages = false;
		else:
			$this->more_pages = true;
		endif;
		
		return;
	}
	
}


?>