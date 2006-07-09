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

require_once 'owa_settings_class.php';
require_once 'owa_lib.php';
require_once 'owa_db.php';

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
class owa_metric {

	/**
	 * Current Time
	 *
	 * @var array
	 */
	var $time_now = array();
	
	/**
	 * Control Paramaters
	 *
	 * @var array
	 */
	var $params = array();
	
	/**
	 * Data
	 *
	 * @var array
	 */
	var $data;
	
	/**
	 * Configuration
	 *
	 * @var array
	 */
	var $config = array();
	
	/**
	 * Databse Access Object
	 *
	 * @var object
	 */
	var $db;
	
	/**
	 * Metric Api
	 *
	 * @var object
	 */
	var $api_type = 'metric';
	
	/**
	 * API Calls
	 *
	 * @var array
	 */
	var $api_calls = array();
	
	/**
	 * The params of the caller, either a report or graph
	 *
	 * @var array
	 */
	var $params;
	
	/**
	 * Error handler
	 *
	 * @var object
	 */
	var $e;
	
	/**
	 * Summary Framework Object
	 *
	 * @var object
	 */
	var $summaryFramework;

	/**
	 * Constructor
	 *
	 * @access public
	 * @return owa_metric
	 */
	function owa_metric() {
	
		$this->config = &owa_settings::get_settings();
		$this->e = &owa_error::get_instance();
		$this->db = &owa_db::get_instance();
		
		// Setup time and query periods
		$this->time_now = owa_lib::time_now();
		
		if ($this->config['use_summary_tables']):
		// This is a stub for getting Summary Framework object
			//$this->summaryFramework = &owa_summary::get_instance();
		endif;
		
		return;
	}
		
	/**
	 * Metric factory
	 *
	 * @param string $class_name
	 * @param array $params
	 * @return object
	 */
	function get_instance($class_name, $params) {
			
		$config = &owa_settings::get_settings();
				
		if (!require_once(OWA_METRICS_DIR.$class_name . '.php')):
			print "error locating proper class file from: " . OWA_METRICS_DIR; //error
		else:  
			$o = new $class_name;
			$o->params = $params;
		endif;	
	
		return $o;
	}

	/**
	 * Time Period SQL constraint
	 *
	 * @access private
	 * @param string $period
	 * @param array $params
	 * @return string $where
	 */
	function time_period($period, $params = null) {	
	
		switch ($period) {
			case "last_24_hours":	
			
				$bound = $this->time_now['timestamp'] - 3600*24;
				$where = sprintf(
							"timestamp >= '%s'",
							$bound
						);
				break;

			case "this_hour":	
				$bound = $this->time_now['timestamp'] - 3600;
				$where = sprintf(
							"timestamp >= '%s'",
							$bound
						);
				break;
				
			case "last_half_hour":	
				$bound = $this->time_now['timestamp'] - 1800;
				$where = sprintf(
							"timestamp >= '%s'",
							$bound
						);	
				break;
				
			case "last_seven_days":	
				$bound = $this->time_now['dayofyear'] - 7;
				$where = sprintf(
							"dayofyear > '%s' and year = '%s'",
							$bound,
							$this->time_now['year']
						);	
				break;
				
			case "today":	
				$where = sprintf(
							"day = '%s' and month = '%s' and year = '%s'",
							$this->time_now['day'],
							$this->time_now['month'],
							$this->time_now['year']
						);
				break;
				
			case "this_week":	
				$where = sprintf(
							"weekofyear = '%s' and year = '%s'",
							$this->time_now['weekofyear'],
							$this->time_now['year']
						);
				break;
				
			case "this_month":	
				$where = sprintf(
							"month = '%s' and year = '%s'",
							$this->time_now['month'],
							$this->time_now['year']
						);
				break;
				
			case "this_year":	
				$where = sprintf(
							"year = '%s'",
							$this->time_now['year']
						);
				break;
				
			case "yesterday":	
				$where = sprintf(
							"day = '%s' and month = '%s' and year = '%s'",
							$this->time_now['day'] - 1,
							$this->time_now['month'],
							$this->time_now['year']
						);
				break;
				
			case "last_week":
				$where = sprintf(
							"weekofyear = '%s' and year = '%s'",
							$this->time_now['weekofyear'] - 1,
							$this->time_now['year']
						);
				break;	
				
			case "last_month":
				$where = sprintf("month = '%s' and year ='%s'",
							$this->time_now['month'] - 1,
							$this->time_now['year']
						);
				break;
				
			case "last_year":
				$where = sprintf("year = '%s'",
							$this->time_now['year'] - 1
						);
				break;
				
			case "same_day_last_week":
				$where = sprintf("dayofyear = '%s' and year = '%s'",
							$this->time_now['dayofyear'] - 7,
							$this->time_now['year']
						);
				break;
				
			case "same_week_last_year":
				$where = sprintf(
							"AND weekofyear = '%s' and year = '%s'",
							$this->time_now['weekofyear'],
							$this->time_now['year'] - 1
						);
				break;
				
			case "same_month_last_year":
				$where = sprintf(
							"month = '%s' and year = '%s'",
							$this->time_now['month'],
							$this->time_now['year'] - 1
						);
				break;
				
			case "all_time":
				$where = sprintf(
							"timestamp <= '%s'",
							$this->time_now['timestamp']
						);
				break;
				
			case "last_tuesday":
				$where = sprintf(
							"dayofweek = '2' and weekofyear = '%s'",
							$this->time_now['weekofyear'] - 1
						);
				break;
			
			case "last_thirty_days":
				//$bound = $this->time_now['timestamp'] - 3600*24*30;
				$bound = $this->time_now['dayofyear'] - 30;
				/*$where = sprintf(
							"AND timestamp >= '%s' and year = '%s'",
							$bound,
							$this->time_now['year']
						);	
				*/
				$where = sprintf(
							"dayofyear > '%s' and year = '%s'",
							$bound,
							$this->time_now['year']
						);	
				
				break;
				
			case "day":
				
				$where = sprintf(
							"day = '%s' and month = '%s' and year = '%s'",
							$this->params['request_params']['day'],
							$this->params['request_params']['month'],
							$this->params['request_params']['year']
						);	
				
				break;
				
			case "month":
				
				$where = sprintf(
							"month = '%s' and year = '%s'",
							$this->params['request_params']['month'],
							$this->params['request_params']['year']
						);	
				
				break;
				
			case "year":
				
				$where = sprintf(
							"year = '%s'",
							$this->params['request_params']['year']
						);	
				
				break;
			
			case "date_range":
				
				$start = mktime(0, 0, 0, $this->params['request_params']['month'], $this->params['request_params']['day'], $this->params['request_params']['year']); 
				$end = mktime(24, 59, 59, $this->params['request_params']['month2'], $this->params['request_params']['day2'], $this->params['request_params']['year2']);
				
				$where = sprintf(
							"timestamp BETWEEN '%s' and '%s'",
							$start,
							$end
						);	
				
				break;
				
			default:
				$where = '';
	
		}
		
		if(!empty($where)):
			return ' AND '.$where;
		else:
			return;
		endif;
	}
	
	/**
	 * Add constraints into SQL where clause
	 *
	 * @param 	array $constraints
	 * @return 	string $where
	 * @access 	public
	 */
	function add_constraints($constraints) {
	
		if (!empty($constraints)):
		//$this->e->debug(' CONSTRAINT: '. print_r($constraints));
		
		
		
		$count = 0;
		
		foreach ($constraints as $key => $value) {
			
			if (!empty($value)):
				$count++;	
			endif;
		}
		
		//print $count;
		
		$i = 0;
		
			foreach ($constraints as $key => $value) {
				
				if ($value):
					$where .= $key . ' = ' . "'$value'";
					$i++;
					if ($count != $i):
					
						$where .= " AND ";
					
					endif;

				endif;		
				
			}
		
			if (!empty($where)):
			
				return $where = ' AND '.$where;
			else: 
				return;
			endif;
			
		else:
		
			return;
				
		endif;
		
		
		
	}
	
	/**
	 * Hook for using summary tables
	 *
	 * @param string $table_name
	 * @return unknown
	 */
	function setTable($table_name) {
		
		if ($this->config['use_summary_tables']):
			// Set summary table suffix
			$table_name = $this->summaryFramework->getTable($table_name, $this->params['period']); 
		endif;
		
		return sprintf("%s%s",
						$this->config['ns'],
						$table_name);
		
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
	
}

?>
