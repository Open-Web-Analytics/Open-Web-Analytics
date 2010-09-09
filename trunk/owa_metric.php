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

require_once(OWA_BASE_CLASS_DIR.'pagination.php');
require_once(OWA_BASE_CLASS_DIR.'timePeriod.php');

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
	
	var $table;
	
	var $select = array();
	
	var $time_period_constraint_format = 'timestamp';
	
	var $column;
	
	var $is_calculated = false;	
	
	var $data_type;
	
	var $supported_data_types = array('percentage', 'decimal', 'integer', 'url', 'yyyymmdd', 'timestamp', 'string', 'currency');
		
	function __construct($params = array()) {
		
		if (!empty($params)) {
			$this->params = $params;
		}
			
		$this->db = owa_coreAPI::dbSingleton();

		$this->pagination = new owa_pagination;
		
		return parent::__construct();
	}
	
	
	/**
	 * @depricated
	 * @remove
	 */
	function applyOptions($params) {
	
		// apply constraints
		if (array_key_exists('constraints', $params)) {
			
			foreach ($params['constraints'] as $k => $v) {
				
				if(is_array($v)) {
					$this->setConstraint($k, $v[1], $v[0]);
				} else {
					$this->setConstraint($k, $value);	
				}				
			}
		}
		
		// apply limit
		if (array_key_exists('limit', $params)) {
			$this->setLimit($params['limit']);
		}
		
		// apply order
		if (array_key_exists('order', $params)) {
			$this->setOrder($params['order']);
		}
		
		// apply page
		if (array_key_exists('page', $params)) {
			$this->setOrder($params['page']);
		}
		
		// apply offset
		if (array_key_exists('offset', $params)) {
			$this->setOrder($params['offset']);
		}
		
		// apply format
		if (array_key_exists('format', $params)) {
			//$this->setFormat($params['format']);
		}
		
		// apply period
		if (array_key_exists('period', $params)) {
			$this->setFormat($params['period']);
		}
		
		// apply start date
		if (array_key_exists('startDate', $params)) {
			$this->setFormat($params['startDate']);
		}

		// apply end date
		if (array_key_exists('endDate', $params)) {
			$this->setFormat($params['endDate']);
		}
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
	
	function setConstraints($array) {
	
		if (is_array($array)) {
			
			if (is_array($this->params['constraints'])) {
				$this->params['constraints'] = array_merge($array, $this->params['constraints']);
			} else {
				$this->params['constraints'] = $array;
			}
		}
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
	
	function setSort($column, $order) {
		
		//$this->params['orderby'][] = array($this->getColumnName($column), $order);
	}
	
	function setSorts($array) {
		
		if (is_array($array)) {
			
			if (!empty($this->params['orderby'])) {
				$this->params['orderby'] = array_merge($array, $this->params['orderby']);

			} else {
				$this->params['orderby'] = $array;
			}
				
		}
		
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
	
	function setTimePeriod($period_name = '', $startDate = null, $endDate = null, $startTime = null, $endTime = null) {
	
		if ($startDate && $endDate) {
			$period_name = 'date_range';
			$map = array('startDate' => $startDate, 'endDate' => $endDate);
		} elseif ($startTime && $endTime) {
			$period_name = 'time_range';
			$map = array('startTime' => $startTime, 'endTime' => $endTime);
		} else {
			$this->debug('no period params passed to owa_metric::setTimePeriod');
			return false;
		}
		
		$p = owa_coreAPI::supportClassFactory('base', 'timePeriod');
		
		$p->set($period_name, $map);
		
		$this->setPeriod($p);
	}
	
	function makeTimePeriod($period = '') {
		
		$start = $this->params['period']->startDate->get($this->time_period_constraint_format);
		$end = $this->params['period']->endDate->get($this->time_period_constraint_format);
		
		if (!empty($this->entity)) {
			$col = $this->entity->getTableAlias().'.'.$this->time_period_constraint_format;
		} else {
			// needed  for backwards compatability
			$col = $this->time_period_constraint_format;
		}
		
		
		$this->params['constraints'][$col] = array('operator' => 'BETWEEN', 'value' => array('start' => $start, 'end' => $end));

		return;
		
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
	 * @depricated
	 */
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
	
	/**
	 * @depricated
	 */
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
		
		// add period info
		$rs->setPeriodInfo($this->params['period']->getAllInfo());
		
		return $rs; 
	}
	
	/**
	 * @depricated
	 */
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
	 * Sets an individual label
	 * return the key so that it can be nested
	 * @return $key string
	 */
	function addLabel($key, $label) {
		
		$this->labels[$key] = $label;
		return $key;
	}
	
	function getLabel($key = '') {
		
		if (!$key) {
			$key = $this->getName();
		}
		
		return $this->labels[$key];
	}
	
	/**
	 * Sets an individual label
	 * return the key so that it can be nested
	 * @return $key string
	 */
	function setLabel($label) {
		
		$this->labels[$this->getName()] = $label;
		
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
	
	function setEntity($name) {
		
		$this->entity = owa_coreAPI::entityFactory($name);
	}
	
	function getTableName() {
		
		return $this->entity->getTableName();
	}
	
	function getTableAlias() {
		
		return $this->entity->getTableAlias();
	}
	
	function setSelect($column, $as = '') {
		
		if (!$as) {
			
			$as = $this->getName();
		}
		
		$this->select = array($column, $as);
	}
	
	function getSelect() {
		
		return $this->select;
	}
	
	function setName($name) {
		
		$this->name = $name;
	}
	
	function getName() {
		
		return $this->name;
	}
	
	function getFormat() {
		
		if (array_key_exists('result_format', $this->params)) {
			return $this->params['result_format'];
		}
	}
	
	/**
	 * Sets a metric's column
	 */
	function setColumn($col_name, $name = '') {
		
		if (!$name) {
			$name = $this->getName();
		}
		$this->column = $this->entity->getTableAlias().'.'.$col_name;
		$this->all_columns[$name] = $this->column;
		
	}
	
	/**
	 * Gets a metric's column name
	 */
	function getColumn() {
		
		return $this->column;
	}
	
	function getEntityName() {
		return $this->entity->getName();
	}
	
	function isCalculated() {
		return $this->is_calculated;
	}
	
	function setDataType($string) {
		
		if (in_array($string, $this->supported_data_types)) {
			$this->data_type = $string;
		}
		
	}
	
	function getDataType() {
		return $this->data_type;
	}
}

?>