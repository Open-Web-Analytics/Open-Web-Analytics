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
	
	var $is_aggregate;
	
	var $data_type;
	
	var $name;
	
	var $supported_data_types = array('percentage', 'decimal', 'integer', 'url', 'yyyymmdd', 'timestamp', 'string', 'currency');
		
	function __construct($params = array()) {
		
		if (!empty($params)) {
			$this->params = $params;
		}
			
		//$this->db = owa_coreAPI::dbSingleton();

		//$this->pagination = new owa_pagination;
		
		return parent::__construct();
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
	/*

	function getPagination() {
		
		$count = $this->calculatePaginationCount();
		$this->pagination->total_count = $count;
		return $this->pagination->getPagination(); 
	
	}
	
	*/
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
	/*

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
	
	*/
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
		
		if ( $this->select) {
			// old style metrics populate this explicitly.
			return $this->select;
		} else {
			$db = owa_coreAPI::dbSingleton();
			switch ( $this->type ) {
				
				case 'count':
					
					$statement = $db->count( $this->getColumn() );
					break;
				
				case 'distinct_count':
					$statement = $db->count( $db->distinct( $this->getColumn() ) );				
					break;
				
				case 'sum':
					$statement = $db->sum( $this->getColumn() );
					break;
			}
			
			return array( $statement, $this->getName() );	
		}
		
	}
	
	function getSelectWithNoAlias() {
		
		if ( $this->select ) {
			return $this->select[0];
		} else {
			$select = $this->getSelect();
			return $select[0];
		}
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
	
	function setAggregate() {
	
		$this->is_aggregate = true;
	}
	
	function isAggregate() {
	
		return $this->is_aggregate;
	}
	
	function setMetricType( $type ) {
		$this->type = $type;
		
		if ( $type === 'calculated' ) {
			 $this->is_calculated = true;
		}
	}
}

?>