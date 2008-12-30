	<?php 

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2008 Peter Adams. All rights reserved.
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
 * Time Period Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2008 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */


class owa_timePeriod {

	var $period;
	var $startDate;
	var $endDate;
	var $label;
	var $diff_years;
	var $diff_months;
	var $diff_days;
	
	function __construct() {
		
		//parent::__construct();
		
		$this->startDate = owa_coreAPI::supportClassFactory('base', 'date');
		$this->endDate = owa_coreAPI::supportClassFactory('base', 'date');
	
		return;
	}
	
	function owa_timePeriod() {
		
		return;
	}
	
	function set($value, $map = array()) {
	
		$this->period = $value;
		
		$this->_setDates($map);
		
		$this->_setLabel($value);
		
		$this->_setDifferences();
		
		return;
	
	}
	
	function getStartDate() {
		return $this->startDate;
	}
	
	function getEndDate() {
		return $this->endDate;
	}
	
	function getLabel() {
		return $this->label;
	}
	
	function get() {
		return $this->period;
	}
	
	function _setLabel($value) {

		if ($value === 'date_range') {
			// Set date labels
			$this->label = $this->startDate->getLabel() . ' - ' . $this->endDate->getLabel();
		} else {
		
			$periods = $this->getPeriodLabels();
			$this->label = $periods[$value]['label'];
		}
		
		return;
	}
	
	/**
	 * Array of Reporting Periods
	 *
	 * @return array
	 */
	function getPeriodLabels() {
		
		return array(
					
					'today' => array('label' => 'Today'),
					'yesterday' => array('label' => 'Yesterday'),
					'this_week' => array('label' => 'This Week'),
					'this_month' => array('label' => 'This Month'),
					'this_year' => array('label' => 'This Year'),
					'last_week'  => array('label' => 'Last Week'),
					'last_month' => array('label' => 'Last Month'),
					'last_year' => array('label' => 'Last Year'),
					'last_half_hour' => array('label' => 'The Last 30 Minutes'),				
					'last_hour' => array('label' => 'Last Hour'),
					'last_24_hours' => array('label' => 'The Last 24 Hours'),
					'last_seven_days' => array('label' => 'The Last Seven Days'),
					'last_thirty_days' => array('label' => 'The Last Thirty Days'),
					'same_day_last_week' => array('label' => 'Same Day last Week'),
					'same_week_last_year' => array('label' => 'Same Week Last Year'),
					'same_month_last_year' => array('label' => 'Same Month Last Year'),
					'date_range' => array('label' => 'Date Range')
		);
		
	}
	
	function _setDates($map = array()) {
		
		$time_now = owa_lib::time_now();
		$nowDate = owa_coreAPI::supportClassFactory('base', 'date');
		$nowDate->set(time(), 'timestamp');
		
		switch ($this->period) {
			
			case "today":
				$end = mktime(0, 0, 0, $time_now['month'], $time_now['day'] + 1, $time_now['year']); 
				$start = $end - 3600*24;			
				break;
				
			case "last_24_hours":
				$end = $time_now['timestamp'];
				$start = $end - 3600*24;
				break;
				
			case "last_hour":
				$end = $time_now['timestamp'];
				$start = $end - 3600;
				break;
				
			case "last_half_hour":
				$end = $time_now['timestamp'];
				$start = $end - 1800;
				break;
				
			case "last_seven_days":
				$end = mktime(0, 0, 0, $time_now['month'], $time_now['day']+1, $time_now['year']);
				$start = $end - 3600*24*7;
				break;
			
			case "this_week":
				$end = mktime(0, 0, 0, $time_now['month'], $time_now['day'], $time_now['year']) + 
				((7 - $nowDate->get('day_of_week')) * 3600 * 24);

				$start = mktime(0, 0, 0, $time_now['month'], $time_now['day'], $time_now['year']) - 
				($nowDate->get('day_of_week') * 3600 * 24);
				break;
				
			case "this_month":
				$start = mktime(0, 0, 0, $time_now['month'], 1 , $time_now['year']);
				$end = mktime(0, 0, 0, $time_now['month'], $nowDate->get('num_days_in_month'), $time_now['year']);
				break;
				
			
			case "this_year":
				$start = mktime(0, 0, 0, 1, 1, $time_now['year']);
				$end = mktime(0, 0, 0, 12, 31, $time_now['year']);
				break;
				
			case "yesterday":
				$end = mktime(0, 0, 0, $time_now['month'], $time_now['day'], $time_now['year']); 
				$start = $end - 3600*24;
				break;
				
			case "last_week":
				$day = ($time_now['day'] - $time_now['dayofweek']) - 7;
				$start = mktime(0, 0, 0, $time_now['month'], $day, $time_now['year']);
				$end = $start + 3600*24*7;
				break;
				
			case "last_month":
				$month =  $this->time_now['month'] - 1;
				$start = mktime(0, 0, 0, $month, 1, $time_now['year']);
				$last = owa_coreAPI::supportClassFactory('base', 'date');
				$last->set($start, 'timestamp');
				$end = mktime(0, 0, 0, $last->get('month'), $last->get('num_days_in_month'), $last->get('year'));
				break;
				
			case "last_year":
				$year = $this->time_now['year'] - 1;
				$start = mktime(0, 0, 0, 1, 1, $year);
				$end = mktime(0, 0, 0, 12, 31, $year);
				break;
				
			case "same_day_last_week":
				$start = mktime(0, 0, 0, $time_now['month'], $time_now['day'], $time_now['year']) - 3600*24*7;
				$end = $start + (3600*24);
				break;
				
			case "same_month_last_year":
				$year = $time_now['year'] - 1;
				$month = $time_now['month'] - 1;
				$start = mktime(0, 0, 0, $month, 1, $year);
				$last = owa_coreAPI::supportClassFactory('base', 'date');
				$last->set($start, 'timestamp');
				$end = mktime(0, 0, 0, $month, $last->get('num_days_in_month'), $year);
				break;
				
			case "all_time":
				$end = time();
				$start = mktime(0, 0, 0, 1, 1, 1969);
				break;
				
			case "last_thirty_days":
				$end = mktime(0, 0, 0, $time_now['month'], $time_now['day']+1, $time_now['year']);
				$start = mktime(0, 0, 0, $time_now['month'], $time_now['day']-29, $time_now['year']);
				break;	
					
			case "date_range":
				list($year, $month, $day) = sscanf($map['startDate'], "%4d%2d%2d");
				$start = mktime(0, 0, 0, $month, $day, $year);		
				list($year, $month, $day) = sscanf($map['endDate'], "%4d%2d%2d");
				$end = mktime(0, 0, 0, $month, $day, $year);
								
				break;
				
			case "time_range":
				$start = $map['startTime'];
				$end = $map['endTime'];				
				break;
				
		}
		
		$this->startDate->set($start, 'timestamp');
		$this->endDate->set($end, 'timestamp');

		return;
	}
	
	function getPeriodProperties() {
	
		$period_params = array();
		$period_params['period'] = $this->get();
		
		if ($period_params['period'] === 'date_range') {
		
			$period_params['startDate'] = $this->startDate->getYyyymmdd();
			$period_params['endDate'] = $this->endDate->getYyyymmdd();	
		
		} elseif ($period_params['period'] === 'time_range') {
		
			$period_params['startTime'] = $this->startDate->getTimestamp();
			$period_params['endTime'] = $this->endDate->getTimestamp();	
		}
		
		return $period_params;
	
	}
	
	function _setDifferences() {
		
		// calc years diff
		$start_year = $this->startDate->getYear();
		$end_year = $this->endDate->getYear();	
		$this->diff_years = $end_year - $start_year;
		
		// calc months diff
		$start_month = $this->startDate->getMonth();
		$end_month = $this->endDate->getMonth();	
		$this->diff_months = ($this->diff_years * 52) + abs($end_month - $start_month);
		
		// calc days diff
		
		return;
		
		
	}
	
	function getMonthsDifference() {
		
		return $this->diff_months;
	}

	function getYearsDifference() {
		
		return $this->diff_years;
	}
	
	function getDaysDifference() {
		
		return $this->diff_days;
	}

		
}



?>