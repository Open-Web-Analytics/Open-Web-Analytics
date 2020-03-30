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
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class owa_timePeriod {

    var $period;
    var $startDate;
    var $endDate;
    var $label;
    var $diff_years;
    var $diff_months;
    var $diff_days;
    var $is_default_period = false;

    function __construct() {

        //parent::__construct();

        $this->startDate = owa_coreAPI::supportClassFactory('base', 'date');
        $this->endDate = owa_coreAPI::supportClassFactory('base', 'date');
    }

    function getDefaultReportingPeriod() {

        return owa_coreAPI::getSetting( 'base', 'default_reporting_period' );
    }

    function setFromMap( $map ) {

        // normalize map
        $m = array(
            'period' => false,
            'startDate' => false,
            'endDate' => false,
            'startTime'  => false,
            'endTime' => false
        );

        $map = owa_lib::array_intersect_key($map, $m);


        // set default period if necessary
        if ( empty( $map[ 'period' ] ) && empty( $map[ 'startDate' ] ) ) {

            $this->is_default_period = true;
            $period = $this->getDefaultReportingPeriod();

        } elseif (  empty( $map[ 'period' ] ) &&  ! empty( $map[ 'startDate' ] ) && ! empty( $map[ 'endDate' ] ) ) {

            $period = 'date_range';

        } else {

            $period = $map['period'];
        }

        //validate period value
        $valid = $this->isValid( $period );

        if ( $valid ) {

            $this->period = $period;


        } else {

            $this->period = $this->getDefaultReportingPeriod();
            owa_coreAPI::debug("$period is not a valid period. Defaulting to default.");
        }

        $this->_setDates( $map );
        $this->_setLabel( $period );
        $this->_setDifferences();

    }

    // checks to see if the period value passsed is valid.
    function isValid( $value ) {

        $valid_periods = $this->getPeriodLabels();
        //add in date_range
        $valid_periods[ 'date_range' ] = '';

        return array_key_exists( $value, $valid_periods );
    }

    function isDefaultPeriod() {

        return $this->is_default_period;
    }

    function set($value = '', $map = array()) {

        $this->period = $value;
        $this->_setDates($map);
        $this->_setLabel($value);
        $this->_setDifferences();
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

    function _setLabel($value = '') {

        $this->label = $this->startDate->getLabel() . ' - ' . $this->endDate->getLabel();
    }

    /**
     * Array of Reporting Periods
     *
     * @return array
     */
    function getPeriodLabels() {

        return array(

                    'today'                 => array('label' => 'Today'),
                    'yesterday'             => array('label' => 'Yesterday'),
                    'this_week'             => array('label' => 'This Week'),
                    'this_month'             => array('label' => 'This Month'),
                    'this_year'             => array('label' => 'This Year'),
                    'last_week'              => array('label' => 'Last Week'),
                    'last_month'             => array('label' => 'Last Month'),
                    'last_year'             => array('label' => 'Last Year'),
                    //'last_half_hour'         => array('label' => 'The Last 30 Minutes'),
                    //'last_hour'             => array('label' => 'Last Hour'),
                    //'last_24_hours'         => array('label' => 'Last 24 Hours'),
                    'last_seven_days'         => array('label' => 'Last Seven Days'),
                    'last_thirty_days'         => array('label' => 'Last Thirty Days'),
                    'same_day_last_week'     => array('label' => 'Same Day last Week'),
                    'same_week_last_year'     => array('label' => 'Same Week Last Year'),
                    'same_month_last_year'     => array('label' => 'Same Month Last Year'),
                    //'date_range'             => array('label' => 'Date Range')
                    //'time_range'            => array('label' => 'Time Range')
        );

    }

    function _setDates($map = array()) {

        $time_now = owa_lib::time_now();
        $nowDate = owa_coreAPI::supportClassFactory('base', 'date');
        $nowDate->set(time(), 'timestamp');
        $start = '';
        $end = '';

        switch ($this->period) {

            case "today":

                $start = mktime(0, 0, 0, $time_now['month'], $time_now['day'], $time_now['year']);
                $end = $start + 3600 * 24 -1;
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
                //$end = mktime(0, 0, 0, $time_now['month'], $time_now['day']+1, $time_now['year']);
                $end = mktime(23, 59, 59, $time_now['month'], $time_now['day'], $time_now['year']);
                $start = $end - 3600*24*7;
                break;

            case "this_week":
                $end = mktime(23, 59, 59, $time_now['month'], $time_now['day'], $time_now['year']) +
                ((6 - $nowDate->get('day_of_week')) * 3600 * 24);
                $start = mktime(0, 0, 0, $time_now['month'], $time_now['day'], $time_now['year']) -
                ($nowDate->get('day_of_week') * 3600 * 24);
                break;

            case "this_month":
                $start = mktime(0, 0, 0, $time_now['month'], 1 , $time_now['year']);
                $end = mktime(23, 59, 59, $time_now['month'], $nowDate->get('num_days_in_month'), $time_now['year']);
                break;

            case "this_year":
                $start = mktime(0, 0, 0, 1, 1, $time_now['year']);
                $end = mktime(23, 59, 59, 12, 31, $time_now['year']);
                break;

            case "yesterday":
                $end = mktime(0, 0, 0, $time_now['month'], $time_now['day'], $time_now['year']);
                $start = $end - 3600*24;
                $end = $end - 1;
                break;

            case "last_week":
                $day = ($time_now['day'] - $time_now['dayofweek']) - 7;
                $start = mktime(0, 0, 0, $time_now['month'], $day, $time_now['year']);
                $end = $start + 3600*24*7;
                break;

            case "last_month":
                $month =  $time_now['month'] - 1;
                $start = mktime(0, 0, 0, $month, 1, $time_now['year']);
                $last = owa_coreAPI::supportClassFactory('base', 'date');
                $last->set($start, 'timestamp');
                $end = mktime(23, 59, 59, $last->get('month'), $last->get('num_days_in_month'), $last->get('year'));
                break;

            case "last_year":
                $year = $time_now['year'] - 1;
                $start = mktime(0, 0, 0, 1, 1, $year);
                $end = mktime(23, 59, 59, 12, 31, $year);
                break;

            case "same_day_last_week":
                $start = mktime(0, 0, 0, $time_now['month'], $time_now['day'], $time_now['year']) - 3600*24*7;
                $end = $start + (3600*24) - 1;
                break;
            ///
            case "same_month_last_year":
                $year = $time_now['year'] - 1;
                $month = $time_now['month'];
                $start = mktime(0, 0, 0, $month, 1, $year);
                $last = owa_coreAPI::supportClassFactory('base', 'date');
                $last->set($start, 'timestamp');
                $end = mktime(23, 59, 59, $month, $last->get('num_days_in_month'), $year);
                break;

            case "all_time":
                $end = time();
                $start = mktime(0, 0, 0, 1, 1, 1969);
                break;

            case "last_thirty_days":
                $end = mktime(23, 59, 59, $time_now['month'], $time_now['day'], $time_now['year']);
                $start = ($end + 1) - (30 * 3600 * 24);
                break;

            case "date_range":
                list($year, $month, $day) = sscanf($map['startDate'], "%4d%2d%2d");
                $start = mktime(0, 0, 0, $month, $day, $year);
                list($year, $month, $day) = sscanf($map['endDate'], "%4d%2d%2d");
                $end = mktime(23, 59, 59, $month, $day, $year);

                break;

            case "time_range":
                $start = $map['startTime'];
                $end = $map['endTime'];
                break;

            case "day":
                list($year, $month, $day) = sscanf($map['startDate'], "%4d%2d%2d");
                $start = mktime(0, 0, 0, $month, $day, $year);
                $end = mktime(23, 59, 59, $month, $day, $year);
                break;

        }

        $this->startDate->set($start, 'timestamp');
        $this->endDate->set($end, 'timestamp');
    }

    function getPeriodProperties() {

        $period_params = array();
        $period_params['period'] = $this->get();
        $period_params['startDate'] = $this->startDate->getYyyymmdd();
        $period_params['endDate'] = $this->endDate->getYyyymmdd();
        //$period_params['startTime'] = $this->startDate->getTimestamp();
        //$period_params['endTime'] = $this->endDate->getTimestamp();
        return $period_params;
    }

    function getAllInfo() {

        $info = array();
        $info['period'] = $this->get();
        $info['startDate'] = $this->startDate->getYyyymmdd();
        $info['endDate'] = $this->endDate->getYyyymmdd();
        $info['startTime'] = $this->startDate->getTimestamp();
        $info['endTime'] = $this->endDate->getTimestamp();
        $info['label'] = $this->getLabel();

        return $info;
    }

    function _setDifferences() {

        // calc years diff
        $start = $this->startDate->getYyyymmdd();
        $end = $this->endDate->getYyyymmdd();
        $diff = $this->getDateDifference($start, $end);

        $this->diff_years = $diff['YearsSince'];
        $this->diff_months = $diff['MonthsSince'];
        $this->diff_days = $diff['DaysSince'];
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

    // Function used to take two date strings, and returns an associative array
    // with different formats for the difference between the dates. 
    // based on function by: tchapin at gmail dot com
    // -------------------- 
    // Variables: 
    // StartDateString (String - MM/DD/YYYY) 
    // EndDateString (String - MM/DD/YYYY) 
    // -------------------- 
    // Example: $DateDiffAry = GetDateDifference('01/09/2008', '02/11/2009'); 
    // print_r($DateDiffAry); 
    // -------------------- 
    // Returns Something Like: 
    /*    
    Array 
    ( 
        [YearsSince] => 1.0931506849315 
        [MonthsSince] => 13.117808219178 
        [DaysSince] => 399 
        [HoursSince] => 9576 
        [MinutesSince] => 574560 
        [SecondsSince] => 34473600 
        [NiceString] => 1 year, 1 month, and 2 days 
        [NiceString2] => Years: 1, Months: 1, Days: 2 
    ) 
    */ 
    function getDateDifference($StartDateString=NULL, $EndDateString=NULL) { 
        $ReturnArray = array(); 
        
        $SDSplit = sscanf($StartDateString,'%4d%2d%2d'); 
        $StartDate = mktime(0,0,0,$SDSplit[1],$SDSplit[2],$SDSplit[0]); 
        
        $EDSplit = sscanf($EndDateString,'%4d%2d%2d'); 
        $EndDate = mktime(0,0,0,$EDSplit[1],$EDSplit[2],$EDSplit[0]); 
        
        $DateDifference = $EndDate-$StartDate; 
        
        $ReturnArray['YearsSince'] = $DateDifference/60/60/24/365; 
        $ReturnArray['MonthsSince'] = $DateDifference/60/60/24/365*12; 
        $ReturnArray['DaysSince'] = $DateDifference/60/60/24; 
        $ReturnArray['HoursSince'] = $DateDifference/60/60; 
        $ReturnArray['MinutesSince'] = $DateDifference/60; 
        $ReturnArray['SecondsSince'] = $DateDifference; 

        $y1 = date("Y", $StartDate); 
        $m1 = date("m", $StartDate); 
        $d1 = date("d", $StartDate); 
        $y2 = date("Y", $EndDate); 
        $m2 = date("m", $EndDate); 
        $d2 = date("d", $EndDate); 
        
        $diff = ''; 
        $diff2 = ''; 
        if (($EndDate - $StartDate)<=0) { 
            // Start date is before or equal to end date! 
            $diff = "0 days"; 
            $diff2 = "Days: 0"; 
        } else { 

            $y = $y2 - $y1; 
            $m = $m2 - $m1; 
            $d = $d2 - $d1; 
            $daysInMonth = date("t",$StartDate); 
            if ($d<0) {$m--;$d=$daysInMonth+$d;} 
            if ($m<0) {$y--;$m=12+$m;} 
            $daysInMonth = date("t",$m2); 
            
            // Nicestring ("1 year, 1 month, and 5 days") 
            if ($y>0) $diff .= $y==1 ? "1 year" : "$y years"; 
            if ($y>0 && $m>0) $diff .= ", "; 
            if ($m>0) $diff .= $m==1? "1 month" : "$m months"; 
            if (($m>0||$y>0) && $d>0) $diff .= ", and "; 
            if ($d>0) $diff .= $d==1 ? "1 day" : "$d days"; 
            
            // Nicestring 2 ("Years: 1, Months: 1, Days: 1") 
            if ($y>0) $diff2 .= $y==1 ? "Years: 1" : "Years: $y"; 
            if ($y>0 && $m>0) $diff2 .= ", "; 
            if ($m>0) $diff2 .= $m==1? "Months: 1" : "Months: $m"; 
            if (($m>0||$y>0) && $d>0) $diff2 .= ", "; 
            if ($d>0) $diff2 .= $d==1 ? "Days: 1" : "Days: $d"; 
            
        }
        
        $ReturnArray['NiceString'] = $diff; 
        $ReturnArray['NiceString2'] = $diff2; 
        return $ReturnArray; 
    }
}

?>