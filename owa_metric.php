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

require_once(OWA_BASE_DIR.DIRECTORY_SEPARATOR.'owa_lib.php');
require_once(OWA_BASE_CLASS_DIR.'pagination.php');

/**
 * Metric
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_metric extends owa_base {

	/**
	 * Current Time
	 *
	 * @var array
	 */
	var $time_now = array();
	
	/**
	 * Data
	 *
	 * @var array
	 */
	var $data;
	
	/**
	 * The params of the caller, either a report or graph
	 *
	 * @var array
	 */
	var $params = array();
		
	/**
	 * The lables for calculated measures
	 *
	 * @var array
	 */
	var $labels = array();
	
	/**
	 * Page results	 
	 *
	 * @var boolean
	 */
	var $page_results = false;
	
	/**
	 * Data Access Object
	 *
	 * @var object
	 */
	var $db;
	
	var $_default_offset = 0;
	
	var $pagination;
	
	var $page;
	
	var $limit;
	
	var $order;
	
	var $time_period_constraint_format = 'timestamp';

	/**
	 * Constructor
	 *
	 * @access public
	 * @return owa_metric
	 */
	function owa_metric($params = '') {

		return owa_metric::__construct($params);
	}
	
	function __construct($params = array()) {
		
		if (!empty($params)):
			$this->params = $params;
		endif;
		
		// Setup time and query periods
		//$this->time_now = owa_lib::time_now();
	
		$this->db = owa_coreAPI::dbSingleton();
		
		$this->pagination = new owa_pagination;
		
		return;
	}
	
	
	/*
	 * Applies overrides specified in the request to the params of the metric.
	 * 
	 */
	function applyOverrides($params = array()) {
		
		foreach ($params as $k => $v) {
			
			if (!empty($v)):
				if (is_array($v)):
					if (!empty($this->params[$k])):
						$this->params[$k] = array_merge($this->params[$k], $v);
					endif;
				else:
					$this->params[$k] = $v;
				endif;
				
				
			endif;
		
		}
		
		return;
	}
	
	function makeTimePeriod($period = '') {
		
		$start = $this->params['period']->startDate->get($this->time_period_constraint_format);
		$end = $this->params['period']->endDate->get($this->time_period_constraint_format);
		$this->params['constraints'][$this->time_period_constraint_format] = array('operator' => 'BETWEEN', 'value' => array('start' => $start, 'end' => $end));

		return;
		
	}
	
	function setConstraint($name, $value, $operator = '') {
		
		if (empty($operator)):
			$operator = '=';
		endif;
		
		if (!empty($value)):
			$this->params['constraints'][$name] = array('operator' => $operator, 'value' => $value, 'name' => $name);
		endif;
		
		return;

	}
	
	function setLimit($value) {
		
		if (!empty($value)):
		
			$this->limit = $value;
		
		endif;
	}
	
	function setOrder($value) {
		
		if (!empty($value)):
		
			$this->params['order'] = $value;
		
		endif;
	}

	
	function setPage($value) {
		
		if (!empty($value)):
		
			$this->page = $value;
			
			if (!empty($this->pagination)):
				$this->pagination->setPage($value);
			endif;
			
		endif;
	}
	

	function getConstraints() {
	
		return $this->params['constraints'];
	}
	
	function setOffset($value) {
		
		if (!empty($value)):
			$this->params['offset'] = $value;
		endif;
	}
	
	function setFormat($value) {
		if (!empty($value)):
			$this->params['result_format'] = $value;
		endif;
	}
	
	function setPeriod($value) {
		if (!empty($value)):
			$this->params['period'] = $value;
		endif;
	}
	
	function setStartDate($date) {
		if (!empty($date)):
			$this->params['startDate'] = $date;
		endif;
	}
	
	function setEndDate($date) {
		if (!empty($date)):
			$this->params['endDate'] = $date;
		endif;
	}

	
		
	/**
	 * Retrieve Result data for a particular metric
	 * @depricated
	 * @param 	array $params
	 * @return 	array $data
	 * @access 	public
	 */
	function get_metric($params) {
	
		$m = owa_metric::get_instance($params['metric_package'], $params);	
		$data = $m->generate($params);
	
		switch ($params['result_format']) {
			case 'a_array':
				return $data;
			case 'inverted_array':
				return $data;
			default:
				return $data;
		}
		
		return $data;
	}
	
	function generate($method = 'calculate') {
		
		$this->makeTimePeriod();
		
		$this->db->multiWhere($this->getConstraints());
				
		if (!empty($this->pagination)):
			$this->pagination->setLimit($this->limit);
		endif;
		
		// pass limit to db object if one exists
		if (!empty($this->limit)):
			$this->db->limit($this->limit);
		endif;
		
		// pass order to db object if one exists
		
		
		
		// pagination
		if (!empty($this->page)):
			$this->pagination->setPage($this->page);
			$offset = $this->pagination->calculateOffset();
			$this->db->offset($offset);
		endif;
	
		
		$results = $this->$method();
		
		if (!empty($this->pagination)):
			$this->pagination->countResults($results);
		endif;
		
		return $results;
	
	}
	
	function generateResults() {
		
		// set period specific constraints
		$this->makeTimePeriod();
		// set constraints
		$this->db->multiWhere($this->getConstraints());
		// sets metric specific SQL
		$this->calculate();
		// generate paginated result set
		$rs = owa_coreAPI::supportClassFactory('base', 'paginatedResultSet');
		// pass limit to db object if one exists
		if (!empty($this->limit)) {
			$rs->setLimit($this->limit);
		}
		
		// pass limit to db object if one exists
		if (!empty($this->page)) {
			$rs->setPage($this->page);
		}
		
		// get results
		$rs->generate($this->db);
		
		// add labels
		$rs->setLabels($this->getLabels());
		
		return $rs; 
	}
	
	function calculatePaginationCount() {
		
		if (method_exists($this, 'paginationCount')):
			$this->makeTimePeriod();
		
			$this->db->multiWhere($this->getConstraints());
		
			return $this->paginationCount();
		else:
			return false;
		endif;
	}
	
	/**
	 * Set the labels of the measures
	 *
	 */
	function setLabels($array) {
	
		$this->labels = $array;
		return;
	}
	
	/**
	 * Retrieve the labels of the measures
	 *
	 */
	function getLabels() {
	
		return $this->labels;
	
	}
	
	function getPagination() {
		
		$count = $this->calculatePaginationCount();
		$this->pagination->total_count = $count;
		return $this->pagination->getPagination(); 
	
	}
	
	function zeroFill(&$array) {
	
		// PHP 5 only function used here
		if (function_exists("array_walk_recursive")) {
			array_walk_recursive($array, array($this, 'addzero'));
		} else {
			owa_lib::array_walk_recursive($array, array(get_class($this).'Metric', 'addzero'));
		}
		
		return $array;
		
	}
	
	function addzero(&$v, $k) {
		
		if (empty($v)) {
			
			$v = 0;
			
		}
		
		return;
	}
	
	function getPeriod() {
	
		return $this->params['period'];
	}
	
	function getOrder() {
	
		if (array_key_exists('order', $this->params)) {
			return $this->params['order'];
		}
	}
	
	function getLimit() {
		
		return $this->limit;
		
	}
	
}

?>