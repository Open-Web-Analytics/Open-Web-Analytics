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
 * Date Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2008 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */


class owa_date {

    var $yyyymmdd;
    var $timestamp;
    var $label;
    var $label_formal;
    var $year;
    var $month;
    var $day;
    var $is_leap_year;
    var $day_of_week;
    var $day_of_week_label;
    var $day_of_year;
    var $day_of_year_label;
    var $week_of_year;
    var $hour;
    var $minute;
    var $second;
    var $microsecond;
    var $meridiem;
    var $num_days_in_month;
    var $utc_offset;

    function __construct() {

        return;
    }

    function set($date, $format = 'yyyymmdd') {

        switch ($format) {

            case 'yyyymmdd':
                $this->yyyymmdd = $date;
                list($this->year, $this->month, $this->day) = sscanf($date, "%4d%2d%2d");
                $this->timestamp = mktime(0, 0, 0, $this->month, $this->day, $this->year);
                break;

            case 'timestamp':
                $this->timestamp = $date;
                $this->yyyymmdd = date('Ymd', $date);
                list($this->year, $this->month, $this->day) = sscanf($this->yyyymmdd, "%4d%2d%2d");
                break;


        }

        $this->utc_offset = date('Z', $this->timestamp);
        $this->hour = date('H', $this->timestamp);
        $this->minute = date('i', $this->timestamp);
        $this->second = date('s', $this->timestamp);
        $this->microsecond = date('u', $this->timestamp);
        $this->meridiem = date('a', $this->timestamp);
        $this->day_of_week = date('w', $this->timestamp);
        $this->day_of_week_label = date('l', $this->timestamp);
        $this->week_of_year = date('W', $this->timestamp);
        $this->day_of_year = date('z', $this->timestamp);
        $this->num_days_in_month = date('t', $this->timestamp);
        $this->label = date('m/d/Y', $this->timestamp);
        $this->label_formal = date('F jS Y', $this->timestamp);
    }

    function get($name){

        return $this->$name;
    }

    function getDay() {
        return $this->day;
    }

    function getMonth() {
        return $this->month;
    }

    function getYear() {
        return $this->year;
    }

    function getLabel($format = '') {

        if (empty($format)) {

            $format = 'label';

        } else {

            $format = 'label_'.$format;
        }

        return $this->$format;
    }

    function getYyyymmdd() {

        return $this->yyyymmdd;
    }

    function getTimestamp() {

        return $this->timestamp;
    }

    function getLocalTimestamp() {

        return $this->getTimestamp() + $this->utc_offset;
    }
}



?>