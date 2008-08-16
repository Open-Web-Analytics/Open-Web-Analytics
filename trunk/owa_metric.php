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
	
	var $db;

	/**
	 * Constructor
	 *
	 * @access public
	 * @return owa_metric
	 */
	function owa_metric($params = '') {

		return $this->__construct($params);
	}
	
	function __construct($params = '') {
		
		if (!empty($params)):
			$this->params = $params;
		endif;
		//print_r($this->params);
		// Setup time and query periods
		$this->time_now = owa_lib::time_now();
		// create timeperiod constraints
		$this->setTimePeriod($this->params['period']);
		//print_r($this->params);
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
		
		if (!empty($period)):	
			$this->params['period'] = $period;
			$this->setTimePeriod();
		endif;
		
		return $this->params['constraints']['timestamp'];
		
	}
	
	function setTimePeriod() {
		
		switch ($this->params['period']) {
			
			case "today":
				
				$end = mktime(0, 0, 0, $this->time_now['month'], $this->time_now['day'] + 1, $this->time_now['year']); 
				$start = $end - 3600*24;

				$this->params['constraints']['timestamp'] = array('operator' => 'BETWEEN', 'value' => array('start' => $start, 'end' => $end));
							
				break;
				
			case "last_24_hours":
				$bound = $this->time_now['timestamp'] - 3600*24;
				$this->params['constraints']['timestamp'] = array('operator' => '>=', 'value' => $bound);
				
				break;
				
			case "last_hour":
				
				$bound = $this->time_now['timestamp'] - 3600;
				$this->params['constraints']['timestamp'] = array('operator' => '>=', 'value' => $bound);
				
				break;
				
			case "last_half_hour":
				
				$bound = $this->time_now['timestamp'] - 1800;
				$this->params['constraints']['timestamp'] = array('operator' => '>=', 'value' => $bound);
				
				break;
				
			case "last_seven_days":
	
				$bound = mktime(23, 59, 59, $this->time_now['month'], $this->time_now['day'], $this->time_now['year']) - 3600*24*7;
				$this->params['constraints']['timestamp'] = array('operator' => '>=', 'value' => $bound);
				
				break;
				
			case "this_week":
				
				$this->params['constraints']['weekofyear'] = array('operator' => '=', 'value' => $this->time_now['weekofyear']);
				$this->params['constraints']['year'] = array('operator' => '=', 'value' => $this->time_now['year']);
				
				break;
				
			case "this_month":
				
				$this->params['constraints']['month'] = array('operator' => '=', 'value' => $this->time_now['month']);
				$this->params['constraints']['year'] = array('operator' => '=', 'value' => $this->time_now['year']);
				
				break;
				
			case "this_year":
				
				$this->params['constraints']['year'] = array('operator' => '=', 'value' => $this->time_now['year']);
				
				break;
				
			case "yesterday":
			
				$end = mktime(0, 0, 0, $this->time_now['month'], $this->time_now['day'], $this->time_now['year']); 
				$start = $end - 3600*24;

				$this->params['constraints']['timestamp'] = array('operator' => 'BETWEEN', 'value' => array('start' => $start, 'end' => $end));

				
				break;
				
			case "last_week":
				
				$daybound = 7 + $this->time_now['dayofweek'];
				
				$end = mktime(23, 59, 59, $this->time_now['month'], $this->time_now['day'], $this->time_now['year']) - 3600*24*$this->time_now['dayofweek'];
				
				$start = mktime(23, 59, 59, $this->time_now['month'], $this->time_now['day'], $this->time_now['year']) - 3600*24*$daybound;
				
				$this->params['constraints']['timestamp'] = array('operator' => 'BETWEEN', 'value' => array('start' => $start, 'end' => $end));

			
				break;
				
			case "last_month":
				
				$bound = mktime(12, 0, 0, $this->time_now['month']-1, $this->time_now['day'], $this->time_now['year']);
				
				$this->params['constraints']['month'] = date("n", $bound); 
				$this->params['constraints']['year'] = date("Y", $bound);
				
				break;
				
			case "last_year":
			
				$bound = $this->time_now['year'] - 1;
			
				$this->params['constraints']['year'] = array('operator' => '=', 'value' => $bound);
				
				break;
				
			case "same_day_last_week":
				
				$bound = mktime(12, 0, 0, $this->time_now['month'], $this->time_now['day'], $this->time_now['year']) - 3600*24*7;
				
				$this->params['constraints']['day'] = array('operator' => '=', 'value' => date("d", $bound));
				$this->params['constraints']['month'] = array('operator' => '=', 'value' => date("n", $bound)); 
				$this->params['constraints']['year'] = array('operator' => '=', 'value' => date("Y", $bound));
				
				break;
			
			case "same_week_last_year":
				
				$this->params['constraints']['weekofyear'] = array('operator' => '=', 'value' => $this->time_now['weekofyear']); 
				
				$bound = $this->time_now['year'] - 1;
				$this->params['constraints']['year'] = array('operator' => '=', 'value' => $bound);
				
				break;
				
			case "same_month_last_year":
				
				$bound = $this->time_now['year'] - 1;
				$this->params['constraints']['month'] = array('operator' => '=', 'value' => $this->time_now['month']); 
				$this->params['constraints']['year'] = array('operator' => '=', 'value' => $bound);
				
				break;
				
			case "all_time":
				
				$this->params['constraints']['timestamp'] = array('operator' => '<=', 'value' => $this->time_now['timestamp']);
				
				break;
				
			case "last_tuesday":
				
				$this->params['constraints']['dayofweek'] = 2; 
				$this->params['constraints']['weekofyear'] = $this->time_now['weekofyear'] - 1;
				
				break;
			
			case "last_thirty_days":
				
				$end = mktime(0, 0, 0, $this->time_now['month'], $this->time_now['day']+1, $this->time_now['year']);
				
				$start = mktime(0, 0, 0, $this->time_now['month'], $this->time_now['day']-29, $this->time_now['year']);
				//$start = $end - 3600*24*29;
				//$this->params['constraints']['timestamp'] = array('operator' => '>=', 'value' => $bound);
				
				$this->params['constraints']['timestamp'] = array('operator' => 'BETWEEN', 'value' => array('start' => $start, 'end' => $end));


				
				break;	
				
			case "day":
				
				$end = mktime(0, 0, 0, $this->params['month'], $this->params['day'] + 1, $this->params['year']);
				
				$start = $end - 3600*24;
				
				$this->params['constraints']['timestamp'] = array('operator' => 'BETWEEN', 'value' => array('start' => $start, 'end' => $end));

				
				break;
				
			case "month":
				
				$end = mktime(0, 0, 0, $this->params['month'] + 1, 0, $this->params['year']);
				
				$start = mktime(0, 0, 0, $this->params['month'], 0, $this->params['year']);
				
				$this->params['constraints']['timestamp'] = array('operator' => 'BETWEEN', 'value' => array('start' => $start, 'end' => $end));

				
				break;
				
			case "year":
				
				$end = mktime(0, 0, 0, 1, 1, $this->params['year'] + 1);
				$start = mktime(0, 0, 0, 1, 1, $this->params['year']);
				
				$this->params['constraints']['timestamp'] = array('operator' => 'BETWEEN', 'value' => array('start' => $start, 'end' => $end));

				
				break;	
				
			case "date_range":
				
				$start = mktime(0, 0, 0, $this->params['month'], $this->params['day'], $this->params['year']); 
				$end = mktime(0, 0, 0, $this->params['month2'], $this->params['day2'] + 1, $this->params['year2']);
				
				$this->params['constraints']['timestamp'] = array('operator' => 'BETWEEN', 'value' => array('start' => $start, 'end' => $end));

				
				break;
				
			case "time_range":
				
				$start = $this->params['start_time'];
				$end = $this->params['end_time'];
				$this->params['constraints']['timestamp'] = array('operator' => 'BETWEEN', 'value' => array('start' => $start, 'end' => $end));
				
				break;
				
		}
		
		
		
		
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
			$this->params['limit'] = $value;
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
	
	function setOrder($value) {
		if (!empty($value)):
			$this->params['order'] = $value;
		endif;
	}
	
	/**
	 * Retrieve Result data for a particular metric
	 *
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
	
	function generate() {
		
		$this->makeTimePeriod();
		return $this->calculate();
	
	}
}

?>