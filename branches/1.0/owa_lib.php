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

require_once 'owa_env.php';
require_once (OWA_PEARLOG_DIR . '/Log.php');

/**
 * Utility Functions
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_lib {

	/**
	 * Convert Associative Array to String
	 *
	 * @param string $inner_glue
	 * @param string $outer_glue
	 * @param array $array
	 * @return string 
	 */
	function implode_assoc($inner_glue, $outer_glue, $array) {
	   $output = array();
	   foreach( $array as $key => $item ) {
			  $output[] = $key . $inner_glue . $item;
		}
		
		return implode($outer_glue, $output);
	}
			
	/**
	 * Deconstruct Associative Array 
	 *
	 * For example this takes array([1] => array(a => dog, b => cat), [2] => array(a => sheep, b => goat))
	 * and tunrs it into array([a] => array(dog, sheep), [b] => array(cat, goat)) 
	 * 
	 * @param array $a_array
	 * @return array $data_arrays
	 * @access public
	 */
	function deconstruct_assoc($a_array) {
		
		$data_arrays = array();
	
		if(!empty($a_array[1])) :
		
			foreach ($a_array as $key => $value) {
				foreach ($value as $k => $v) {
					$data_arrays[$k][] = $v;
			
				}
			}
		else:
			//print_r($a_array[0]);
			foreach ($a_array[0] as $key => $value) {
				$data_arrays[$key][] = $value;
			}
		endif;
		
		return $data_arrays;
	}
	
	
	function decon_assoc($a_array) {
		
		$data_arrays = array();
	
		foreach ($a_array as $key => $value) {
			//foreach ($value as $k => $v) {
				$data_arrays[$key][] = $value;
		
			//}
		}
		
		return $data_arrays;
	}

	/**
	 * Array of Current Time
	 *
	 * @return array
	 * @access public
	 */
	function time_now() {
		
		$timestamp = time();
		
		return array(
			
				'year' 				=> date("Y", $timestamp),
				'month' 			=> date("n", $timestamp),
				'day' 				=> date("d", $timestamp),
				'dayofweek' 		=> date("D", $timestamp),
				'dayofyear' 		=> date("z", $timestamp),
				'weekofyear'		=> date("W", $timestamp),
				'hour'				=> date("G", $timestamp),
				'minute' 			=> date("i", $timestamp),
				'second' 			=> date("s", $timestamp),
				'timestamp'			=> $timestamp
			);
	}
		
	/**
	 * Stub of debug Handler
	 *
	 * @return string
	 * @access 	public
	 * @static 
	 */
	function &get_debugmsgs() {
		
		static $msgs;
		return $msgs;
	}
		
	/**
	 * Error Handler
	 *
	 * @param string $msg
	 * @access public
	 */
	function errorHandler($msg) {
		
		$conf = array('mode' => 0755, 'timeFormat' => '%X %x');
		$error_logger = &Log::singleton('file', $this->config['error_log_file'], 'ident', $conf);
		$this->error_logger->_lineFormat = '[%3$s]';
		
		return;
	}
	
	/**
	 * Information array for Months in the year.
	 *
	 * @return array
	 */
	function months() {
		
		return array(
					
					1 => array('label' => 'January'),
					2 => array('label' => 'February'),
					3 => array('label' => 'March'),
					4 => array('label' => 'April'),
					5 => array('label' => 'May'),
					6 => array('label' => 'June'),
					7 => array('label' => 'July'),
					8 => array('label' => 'August'),				
					9 => array('label' => 'September'),
					10 => array('label' => 'October'),
					11 => array('label' => 'November'),
					12 => array('label' => 'December')
		);
		
	}
	
	function days() {
		
		return array(1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 
					15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31);
	}
	
	function years() {
		
		static $years;
		
		if (empty($years)):
			
			$start_year = 2005;
			
			$years = array($start_year);
			
			$num_years =  date("Y", time()) - $start_year;
			
			for($i=1; $i<=$num_years; $i++) {
		 	
				$years[] = $start_year + $i;
			}
			
			$years = array_reverse($years);
		
		endif;
		
		return $years;
	}
	
	
	/**
	 * Returns a label from an array of months
	 *
	 * @param int $month
	 * @return string
	 */
	function get_month_label($month) {
		
		static $months;
		
		if (empty($months)):

			$months = owa_lib::months();
		
		endif;  
		
		return $months[$month]['label'];
		
	}
	
	
	/**
	 * Sets the suffix for Days used in Date labels
	 *
	 * @param string $day
	 * @return string
	 */
	function setDaySuffix($day) {
		
		switch ($day) {
			
			case "1":
				$day_suffix = 'st';
				break;
			case "2":
				$day_suffix = 'nd';
				break;
			case "3":
				$day_suffix = 'rd';
				break;
			default:
				$day_suffix = 'th';
		}
		
		return $day_suffix;
		
	}
	
	/**
	 * Generates the label for a date
	 *
	 * @param array $params
	 * @return string
	 */
	function getDatelabel($params) {
		
		switch ($params['period']) {
		
			case "day":
				return sprintf("%s, %d%s %s",
							owa_lib::get_month_label($params['month']),
							$params['day'],
							$day_suffix,
							$params['year']				
						);
				break;
			
			case "month":
				return sprintf("%s %s",
							owa_lib::get_month_label($params['month']),
							$params['year']				
						);
				break;
			
			case "year":	
				return sprintf("%s",
							$params['year']				
						);
				break;
			case "date_range":
				return sprintf("%s, %d%s %s - %s, %d%s %s",
							owa_lib::get_month_label($params['month']),
							$params['day'],
							owa_lib::setDaySuffix($params['day']),
							$params['year'],
							owa_lib::get_month_label($params['month2']),
							$params['day2'],
							owa_lib::setDaySuffix($params['day2']),
							$params['year2']					
						);
				break;
		}
		
		return false;
		
	}
	
	/**
	 * Array of Reporting Periods
	 *
	 * @return array
	 */
	function reporting_periods() {
		
		return array(
					
					'today' => array('label' => 'Today'),
					'yesterday' => array('label' => 'Yesterday'),
					'this_week' => array('label' => 'This Week'),
					'this_month' => array('label' => 'This Month'),
					'this_year' => array('label' => 'This Year'),
					'last_month' => array('label' => 'Last Month'),
					'last_year' => array('label' => 'Last Year'),
					'last_half_hour' => array('label' => 'The Last 30 Minutes'),				
					'last_24_hours' => array('label' => 'The Last 24 Hours'),
					'last_seven_days' => array('label' => 'The Last Seven Days'),
					'last_thirty_days' => array('label' => 'The Last Thirty Days'),
					'this_hour' => array('label' => 'This Hour'),
					'same_day_last_week' => array('label' => 'Same Day last Week'),
					'same_week_last_year' => array('label' => 'Same Week Last Year'),
					'same_month_last_year' => array('label' => 'Same Month Last Year'),
					//'day' => array('label' => 'Day'),
					//'month' => array('label' => 'Month'),
					//'year' => array('label' => 'Year'),
					//'date_range' => array('label' => 'Date Range')
		);
		
	}
	
	/**
	 * Array of Date specific Reporting Periods
	 *
	 * @return array
	 */
	function date_reporting_periods() {
		
		return array(
					
					'day' => array('label' => 'Day'),
					'month' => array('label' => 'Month'),
					'year' => array('label' => 'Year'),
					'date_range' => array('label' => 'Date Range')
		);
		
	}
	
	/**
	 * Gets label for a particular reporting period
	 *
	 * @param unknown_type $period
	 * @return unknown
	 */
	function get_period_label($period) {
	
		$periods = owa_lib::reporting_periods();
		
		return $periods[$period]['label'];
	}
	
	/**
	 * Assembles the current URL from request params
	 *
	 * @return string
	 */
	function get_current_url() {
		
		$url = 'http';	
		
		if($_SERVER['HTTPS']=='on'):
			$url.= 's';
		endif;
		
		$url .= '://'.$_SERVER['SERVER_NAME'];
		
		if($_SERVER['SERVER_PORT'] != 80):
			$url .= ':'.$_SERVER['SERVER_PORT'];
		endif;
		
		$url .= $_SERVER['REQUEST_URI'];
		
		return $url;
	}
	
	/**
	 * Builds date param array from GET
	 *
	 * @return array
	 */
	function getRestparams() {
		
		$config = &owa_settings::get_settings();
		
		$params = array();
		
		$params['owa_action'] = $_GET['owa_action'];
		$params['owa_page'] = $_GET['owa_page'];
		$params['year'] = $_GET['year'];
		$params['month'] = $_GET['month'];
		$params['day'] = $_GET['day'];
		$params['dayofyear'] = $_GET['dayofyear'];
		$params['weekofyear'] = $_GET['weekofyear'];
		$params['hour'] = $_GET['hour'];
		$params['minute'] = $_GET['minute'];
		$params['year2'] = $_GET['year2'];
		$params['month2'] = $_GET['month2'];
		$params['day2'] = $_GET['day2'];
		$params['dayofyear2'] = $_GET['dayofyear2'];
		$params['weekofyear2'] = $_GET['weekofyear2'];
		$params['hour2'] = $_GET['hour2'];
		$params['minute2'] = $_GET['minute2'];
		$params['limit'] = $_GET['limit'];
		$params['offset'] = $_GET['offset'];
		$params['sortby'] = $_GET['sortby'];
		$params['period'] = $_GET['period'];
		$params['site_id'] = $_GET['site_id'];
		$params['type'] = $_GET['type'];
		$params['api_call'] = $_GET['name'];
		$params['session_id'] = $_GET['session_id'];
		$params['visitor_id'] = $_GET['visitor_id'];
		$params['document_id'] = $_GET['document_id'];
		$params['referer_id'] = $_GET['referer_id'];
		$params['source'] = $_GET['source'];
	
		return $params;
		
	}
}

?>
