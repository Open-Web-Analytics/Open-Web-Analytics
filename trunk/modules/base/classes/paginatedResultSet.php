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

		
	var $timePeriod;
	var $resultsPerPage;
	var $resultsTotal;
	var $resultsReturned;
	var $resultsRows;
		
	/**
	 * Aggregate values for metrics
	 */
	var $aggregates = array();
	
	var $rows;
	
	var $labels;
	
	var $more;
	var $page = 1;
	var $total_pages;
		
	/**
	 * The URL that produces the results
	 */
	var $self;
	
	/**
	 * The URL that produces the next page of results
	 */	
	var $next;
	
	var $results_count;
	var $offset = 0;
	var $limit;
	var $query_limit;

	
	function __construct() {
	
	}
	
	function setLimit($limit) {
	
		$this->resultsPerPage = $limit;
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
	
		$this->resultsReturned = count($results);
		$this->results_count = count($results);
				
		if ($this->limit) {
			$this->total_pages = ceil(($this->results_count + $this->offset) / $this->limit);
			
			if ($this->results_count < $this->limit) {
			// no more pages
			} else {
				// more pages
				$this->setMorePages();
				
			}
		}
	}
	
	function getRowCount() {
		
		return $this->results_count;
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
		
		return $this->rows;
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
		$this->timePeriod = $info;
	}
	
	function getLabel($key) {
		
		if (array_key_exists($key, $this->labels)) {
			return $this->labels[$key];
		}
	}
	
	function getAllLabels() {
	
		return $this->labels;
	}
	
	
	function formatResults($format) {
		
		$formats = array('html' => 'resultSetToHtml',
						 'json'	=>	'resultSetToJson',
						 'xml'	=>	'resultSetToXml',
						 'php'	=>	'resultSetToSerializedPhp',
						 'csv'	=>	'resultSetToCsv',
						 'debug' => 'resultSetToDebug');
		
		if (array_key_exists($format, $formats)) {
			
			return $this->$formats[$format]();
		} else {
			return 'That format is not supported';
		}
				
	}
	
	
	function resultSetToXml() {
	
		$t = new owa_template;
		
		$t->set_template('resultSetXml.php');
		$t->set('rs', $this);
		
		return $t->fetch();	
	}
	
	function resultSetToJson() {
		return json_encode($this);
	}
	
	function resultSetToDebug() {
		
		return print_r($this, true);
	}
	
	function resultSetToSerializedPhp() {
		return serialize($this);
	}
	
	function resultSetToHtml() {
		$t = new owa_template;
		
		$t->set_template('resultSetHtml.php');
		$t->set('rs', $this);
		
		return $t->fetch();	
	}
	
	function getDataRows() {
		return $this->resultsRows;
	}
	
	function getSeries($name) {
		
		$rows = $this->getDataRows();
		
		if ($rows) {
			$series = array();
			foreach ($rows as $row) {
				foreach($row as $item) {
					if ($item['name'] === $name) {
						$series[] = $item['value'];
					}
				}
			}
			return $series;			
		} else {
			return false;
		}
	}
	
}


?>