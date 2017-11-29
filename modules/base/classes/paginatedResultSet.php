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

	/**
	 * Unique hash of result set used by front end
	 * to see if there are any changes.
	 */
	var $guid;
	
	var $timePeriod;
	var $resultsPerPage = 25;
	var $resultsTotal;
	var $resultsReturned;
	var $resultsRows = array();
	var $sortColumn;
	var $sortOrder;
		
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
	 * The API URL that produces the results
	 */
	var $self;
	
	/**
	 * The API URL that produces the next page of results
	 */	
	var $next;
	
	/**
	 * The API URL that produces the previous page of results
	 */	
	var $previous;
	
	/**
	 * The base API URL that is used to construct client side pagination links. 
	 * Does not contain any 'page' params.
	 */	
	var $base_url;
	
	/**
	 * The list of related dimensions that can be added to the result set
	 *
	 */
	var $relatedDimensions = array();
	
	/**
	 * The list of related metrics that can be added to the result set
	 *
	 */
	var $relatedMetrics = array();
	
	var $results_count = 0;
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
	
		$this->resultsTotal = count($results);
		$this->results_count = count($results);
				
		if ($this->limit) {
			$this->total_pages = ceil(($this->results_count + $this->offset) / $this->limit);
			
			if ($this->results_count <= $this->limit) {
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
			owa_coreAPI::debug('applying limit of: '.$this->limit);	
			$dao->limit($this->limit * 10);
		}
		
		if (!empty($this->page)) {
		
			$dao->offset($this->calculateOffset());
		} else {
			$this->page = 1;
		}
		
		$results = $dao->$method();
		if (!empty($results)) {
			$this->countResults($results);
			
			if ($this->resultsPerPage) {
				$this->rows = array_slice($results, 0, $this->limit);
			} else {
				$this->rows = $results;
			}
			
			$this->resultsReturned = count($this->rows);
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
						 'jsonp' => 'resultSetToJsonp',
						 'xml'	=>	'resultSetToXml',
						 'php'	=>	'resultSetToSerializedPhp',
						 'csv'	=>	'resultSetToCsv',
						 'debug' => 'resultSetToDebug');
		
		if ( array_key_exists( $format, $formats ) ) {
			
			$method = $formats[ $format ];
			
			return $this->$method();
			
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
	
	function resultSetToJsonp($callback = '') {
		
		// if not found look on the request scope.
		if ( ! $callback ) {
			$callback = owa_coreAPI::getRequestParam('jsonpCallback');
		}
		
		if ( ! $callback ) {
			
			return $this->resultSetToJson();
		}
		
		$t = new owa_template;
		$t->set_template('json.php');
		
		// set
		$body = sprintf("%s(%s);", $callback, json_encode( $this ) );
		
		$t->set('json', $body);
		return $t->fetch();
	}
	
	function resultSetToDebug() {
		
		return print_r($this, true);
	}
	
	function resultSetToSerializedPhp() {
		return serialize($this);
	}
	
	function resultSetToHtml($class = 'dimensionalResultSet') {
		$t = new owa_template;
		
		$t->set_template('resultSetHtml.php');
		$t->set('rs', $this);
		$t->set('class', $class);
		
		return $t->fetch();	
	}
	
	function getDataRows() {
		return $this->resultsRows;
	}
	
	function getResultsRows() {
		return $this->resultsRows;
	}
	
	function addLinkToRowItem($item_name, $template, $subs) {
		
				
		foreach ($this->resultsRows as $k => $row) {
			
			$sub_array = array();
			
			foreach ($subs as $sub) {
				$sub_array[] = urlencode($this->resultsRows[$k][$sub]['value']);	
			}
		
			$this->resultsRows[$k][$item_name]['link'] = vsprintf($template, $sub_array);		
		}
		
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
	
	function getAggregateMetric($name) {
		 
		if ( array_key_exists( $name, $this->aggregates ) ) {
			return $this->aggregates[$name]['value'];
		} else {
			owa_coreAPI::debug( "No aggregate metric called $name found." );
		}
	}
	
	function setAggregateMetric($name, $value, $label, $data_type, $formatted_value = '') {
		
		$this->aggregates[$name] = array('result_type' => 'metric', 'name' => $name, 'value' => $value, 'label' => $label, 'data_type' => $data_type, 'formatted_value' => $formatted_value);
	}
	
	function appendRow($row_num, $type, $name, $value, $label, $data_type, $formatted_value = '') {
	
		$this->resultsRows[$row_num][$name] = array(
			'result_type' 		=> $type, 
			'name' 				=> $name, 
			'value' 			=> $value, 
			'label' 			=> $label, 
			'data_type' 		=> $data_type, 
			'formatted_value' 	=> $formatted_value
		);	
	}
	
	function removeMetric($name) {
		
		if (array_key_exists($name, $this->aggregates)) {
			
			unset($this->aggregates[$name]);
		}
		
		if ($this->getRowCount() > 0) {
			
			foreach ($this->resultsRows as $k => $row) {
				
				if (array_key_exists($name, $row)) {
			
					unset($this->resultsRows[$k][$name]);
				}
			}
		}
	}
	
	function createResultSetHash() {
		
		$this->guid = md5(serialize($this));
	}
	
	function setRelatedDimensions( $dims = '' ) {
		
		if ( $dims ) {
			$this->relatedDimensions = $dims;
		}
	}
	
	function setRelatedMetrics( $metrics = '' ) {
		
		if ( $metrics ) {
			$this->relatedMetrics = $metrics;
		}
	}
}

?>