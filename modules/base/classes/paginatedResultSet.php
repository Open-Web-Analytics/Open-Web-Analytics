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
class owa_paginatedResultSet {

	var $page = 1;
	
	var $limit;
	
	var $offset = 0;
	
	var $results_count;
	
	var $rows;
	
	var $more;
	
	var $total_pages;
	
	var $labels;
	
	var $query_limit;
	
	var $periodInfo;
	
	/**
	 * The URL that produces the results
	 */
	var $self;
	
	/**
	 * The URL that produces the next page of results
	 */	
	var $next;
	
	/**
	 * Aggregate values for metrics
	 */
	var $aggregates = array();
	
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
		$this->total_pages = ceil(($this->results_count + $this->offset) / $this->limit);
		
		if ($this->results_count < $this->limit) {
			// no more pages
		} else {
			// more pages
			$this->setMorePages();
			
		}
	}
	
	function generate($dao, $method = 'getAllRows') {
		
		if (!empty($this->limit)) {
			// query for more than we need	
			$dao->limit($this->limit * 5);
		}
		
		if (!empty($this->page)) {
		
			$dao->offset($this->calculateOffset());
		}
		
		$results = $dao->$method();
		
		if (!empty($results)) {
			$this->countResults($results);	
			$this->rows = array_slice($results, 0, $this->limit);
		} else {
			$this->rows = array();
		}
		
	}
	
	function getResultSetAsArray() {
		
		$set = array();
		
		$set['labels'] = $this->labels;
		$set['rows'] = $this->rows;
		$set['count'] = $this->results_count;
		$set['page'] = $this->page;
		$set['total_pages'] = $this->total_pages;
		$set['more'] = $this->more;
		$set['period'] = $this->getPeriodInfo();		
		return $set;
	}
	
	function setLabels($labels) {
		
		$this->labels = $labels;
	}
	
	function displayPagination() {
		
		
	}
	
	function getPeriodInfo() {
		return $this->periodInfo;
	}
	
	function setPeriodInfo($info) {
		$this->periodInfo = $info;
	}
	
	function getLabel($key) {
		
		if (array_key_exists($key, $this->labels)) {
			return $this->labels[$key];
		}
	}
	
	function getAllLabels() {
	
		return $this->labels;
	}
	
	function resultsToXml() {
	
	}
	
	function resultsToJson() {
	
	}
	
}


?>