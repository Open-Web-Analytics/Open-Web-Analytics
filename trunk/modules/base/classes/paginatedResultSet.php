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
class owa_paginatedResultSet extends owa_base {

	var $page = 1;
	
	var $limit;
	
	var $offset = 0;
	
	var $results_count;
	
	var $rows;
	
	var $more;
	var $total_pages;
	
	var $labels;
	
	var $query_limit;
	
	
	
	function __construct() {
	
	}
	
	function setLimit($limit) {
	
		$this->limit = $limit;
	}
	
	function setPage($page) {
		
		$this->page = $page;
	}
	
	function setMorePages() {
		
		$this->more = true;
	}
	
	function calculateOffset() {
		
		$this->offset = $this->limit * ($this->page - 1);
		return $this->offset;
	}
	
	function countResults($results) {
	
		$this->results_count = count($results);
		
		if ($this->results_count < $this->limit) {
			// no more pages
		} else {
			// more pages
			$this->setMorePages();
			$this->total_pages = ceil($this->results_count / $this->limit);
		}
	}
	
	function generate($dao, $method = 'getAllRows') {
		
		if (!empty($this->limit)) {
			// query for more than we need	
			$dao->limit($this->limit * 3);
		}
		
		if (!empty($this->page)) {
		
			$dao->offset($this->calculateOffset());
		}
		
		$results = $dao->$method();
		$this->countResults($results);
		$this->rows = array_slice($results, 0, $this->limit);
		
	}
	
	function getResultSetAsArray() {
		
		$set = array();
		
		$set['rows'] = $this->rows;
		$set['count'] = $this->results_count;
		$set['page'] = $this->page;
		$set['total_pages'] = $this->total_pages;
		$set['more'] = $this->more;
		$set['labels'] = $this->labels;
		
		return $set;
	}
	
	function setLabels($labels) {
		
		$this->labels = $labels;
	}
	
}


?>