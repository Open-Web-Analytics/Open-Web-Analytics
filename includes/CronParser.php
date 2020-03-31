<?php /* $Id: CronParser.php,v 1.7 2005/09/12 01:04:05 ns Exp $ */

/**####################################################################################################**\
   Version: V1.01
   Release Date: 12 Sep 2005
   Licence: GPL
   By: Nikol S
   Please send bug reports to ns@eyo.com.au
\**####################################################################################################**/

/* This class is based on the concept in the CronParser class written by Mick Sear http://www.ecreate.co.uk
 * The following functions are direct copies from or based on the original class:
 * getLastRan(), getDebug(), debug(), expand_ranges()
 *
 * Who can use this class?
 * This class is idea for people who can not use the traditional Unix cron through shell.
 * One way of using is embedding the calling script in a web page which is often visited.
 * The script will work out the last due time, by comparing with run log timestamp. The scrip
 * will envoke any scripts needed to run, be it deleting older table records, or updating prices.
 * It can parse the same cron string used by Unix.
 */

/* Usage example:

$cron_str0 = "0,12,30-51 3,21-23,10 1-25 9-12,1 0,3-7";
require_once("CronParser.php");
$cron = new CronParser();
$cron->calcLastRan($cron_str0);
// $cron->getLastRanUnix() returns an Unix timestamp
echo "Cron '$cron_str0' last due at: " . date('r', $cron->getLastRanUnix()) . "<p>";
// $cron->getLastRan() returns last due time in an array
print_r($cron->getLastRan());
echo "Debug:<br>" . nl2br($cron->getDebug());

$cron_str1 = "3 12 * * *";
if ($cron->calcLastRan($cron_str1))
{
   echo "<p>Cron '$cron_str1' last due at: " . date('r', $cron->getLastRanUnix()) . "<p>";
   print_r($cron->getLastRan());
}
else
{
   echo "Error parsing";
}
echo "Debug:<br>" . nl2br($cron->getDebug());

 *#######################################################################################################
 */

class CronParser
{

     var $bits = Array(); //exploded String like 0 1 * * *
     var $now = Array();    //Array of cron-style entries for time()
     var $lastRan;         //Timestamp of last ran time.
     var $taken;
     var $debug;
    var $year;
    var $month;
    var $day;
    var $hour;
    var $minute;
    var $minutes_arr = array();    //minutes array based on cron string
    var $hours_arr = array();    //hours array based on cron string
    var $months_arr = array();    //months array based on cron string

    function getLastRan()
    {
        return explode(",", strftime("%M,%H,%d,%m,%w,%Y", $this->lastRan)); //Get the values for now in a format we can use
    }

    function getLastRanUnix()
    {
        return $this->lastRan;
    }

    function getDebug()
    {
         return $this->debug;
    }

    function debug($str)
    {
        if (is_array($str))
        {
            $this->debug .= "\nArray: ";
            foreach($str as $k=>$v)
            {
                $this->debug .= "$k=>$v, ";
            }

        }
        else
        {
            $this->debug .= "\n$str";
        }
        //echo nl2br($this->debug);
    }

    /**
     * Assumes that value is not *, and creates an array of valid numbers that
     * the string represents.  Returns an array.
     */
    function expand_ranges($str)
    {
        if (strstr($str,  ","))
        {
            $arParts = explode(',', $str);
            foreach ($arParts AS $part)
            {
                if (strstr($part, '-'))
                {
                    $arRange = explode('-', $part);
                    for ($i = $arRange[0]; $i <= $arRange[1]; $i++)
                    {
                        $ret[] = $i;
                    }
                }
                else
                {
                    $ret[] = $part;
                }
            }
        }
        elseif (strstr($str,  '-'))
        {
            $arRange = explode('-', $str);
            for ($i = $arRange[0]; $i <= $arRange[1]; $i++)
            {
                $ret[] = $i;
            }
        }
        else
        {
            $ret[] = $str;
        }
        $ret = array_unique($ret);
        sort($ret);
        return $ret;
    }

    function daysinmonth($month, $year)
    {
        return date('t', mktime(0, 0, 0, $month, 1, $year));
    }

    /**
     *  Calculate the last due time before this moment
     */
    function calcLastRan($string)
    {

         $tstart = microtime();
        $this->debug = "";
        $this->lastRan = 0;
        $this->year = NULL;
        $this->month = NULL;
        $this->day = NULL;
        $this->hour = NULL;
        $this->minute = NULL;
        $this->hours_arr = array();
        $this->minutes_arr = array();
        $this->months_arr = array();

        $string = preg_replace('/[\s]{2,}/', ' ', $string);

        if (preg_match('/[^-,* \\d]/', $string) !== 0)
        {
            $this->debug("Cron String contains invalid character");
            return false;
        }

        $this->debug("<b>Working on cron schedule: $string</b>");
         $this->bits = @explode(" ", $string);

        if (count($this->bits) != 5)
        {
            $this->debug("Cron string is invalid. Too many or too little sections after explode");
            return false;
        }

        //put the current time into an array
        $t = strftime("%M,%H,%d,%m,%w,%Y", time());
        $this->now = explode(",", $t);

        $this->year = $this->now[5];

        $arMonths = $this->_getMonthsArray();

        do
        {
            $this->month = array_pop($arMonths);
        }
        while ($this->month > $this->now[3]);

        if ($this->month === NULL)
        {
            $this->year = $this->year - 1;
            $this->debug("Not due within this year. So checking the previous year " . $this->year);
            $arMonths = $this->_getMonthsArray();
            $this->_prevMonth($arMonths);
        }
        elseif ($this->month == $this->now[3]) //now Sep, month = array(7,9,12)
        {
            $this->debug("Cron is due this month, getting days array.");
            $arDays = $this->_getDaysArray($this->month, $this->year);

            do
            {
                $this->day = array_pop($arDays);
            }
            while ($this->day > $this->now[2]);

            if ($this->day === NULL)
            {
                $this->debug("Smallest day is even greater than today");
                $this->_prevMonth($arMonths);
            }
            elseif ($this->day == $this->now[2])
            {
                $this->debug("Due to run today");
                $arHours = $this->_getHoursArray();

                do
                {
                    $this->hour = array_pop($arHours);
                }
                while ($this->hour > $this->now[1]);

                if ($this->hour === NULL) // now =2, arHours = array(3,5,7)
                {
                    $this->debug("Not due this hour and some earlier hours, so go for previous day");
                    $this->_prevDay($arDays, $arMonths);
                }
                elseif ($this->hour < $this->now[1]) //now =2, arHours = array(1,3,5)
                {
                    $this->minute = $this->_getLastMinute();
                }
                else // now =2, arHours = array(1,2,5)
                {
                    $this->debug("Due this hour");
                    $arMinutes = $this->_getMinutesArray();
                    do
                    {
                        $this->minute = array_pop($arMinutes);
                    }
                    while ($this->minute > $this->now[0]);

                    if ($this->minute === NULL)
                    {
                        $this->debug("Not due this minute, so go for previous hour.");
                        $this->_prevHour($arHours, $arDays, $arMonths);
                    }
                    else
                    {
                        $this->debug("Due this very minute or some earlier minutes before this moment within this hour.");
                    }
                }
            }
            else
            {
                $this->debug("Cron was due on " . $this->day . " of this month");
                $this->hour = $this->_getLastHour();
                $this->minute = $this->_getLastMinute();
            }
        }
        else //now Sep, arrMonths=array(7, 10)
        {
            $this->debug("Cron was due before this month. Previous month is: " . $this->year . '-' . $this->month);
            $this->day = $this->_getLastDay($this->month, $this->year);
            if ($this->day === NULL)
            {
                //No scheduled date within this month. So we will try the previous month in the month array
                $this->_prevMonth($arMonths);
            }
            else
            {
                $this->hour = $this->_getLastHour();
                $this->minute = $this->_getLastMinute();
            }
        }

        $tend = microtime();
        $this->taken = $tend - $tstart;
        $this->debug("Parsing $string taken " . $this->taken . " seconds");

        //if the last due is beyond 1970
        if ($this->minute === NULL)
        {
            $this->debug("Error calculating last due time");
            return false;
        }
        else
        {
            $this->debug("LAST DUE: " . $this->hour . ":" . $this->minute . " on " . $this->day . "/" . $this->month . "/" . $this->year);
            $this->lastRan = mktime($this->hour, $this->minute, 0, $this->month, $this->day, $this->year);
            return true;
        }
    }

    //get the due time before current month
    function _prevMonth($arMonths)
    {
        $this->month = array_pop($arMonths);
        if ($this->month === NULL)
        {
            $this->year = $this->year -1;
            if ($this->year <= 1970)
            {
                $this->debug("Can not calculate last due time. At least not before 1970..");
            }
            else
            {
                $this->debug("Have to go for previous year " . $this->year);
                $arMonths = $this->_getMonthsArray();
                $this->_prevMonth($arMonths);
            }
        }
        else
        {
            $this->debug("Getting the last day for previous month: " . $this->year . '-' . $this->month);
            $this->day = $this->_getLastDay($this->month, $this->year);

            if ($this->day === NULL)
            {
                //no available date schedule in this month
                $this->_prevMonth($arMonths);
            }
            else
            {
                $this->hour = $this->_getLastHour();
                $this->minute = $this->_getLastMinute();
            }
        }

    }

    //get the due time before current day
    function _prevDay($arDays, $arMonths)
    {
        $this->debug("Go for the previous day");
        $this->day = array_pop($arDays);
        if ($this->day === NULL)
        {
            $this->debug("Have to go for previous month");
            $this->_prevMonth($arMonths);
        }
        else
        {
            $this->hour = $this->_getLastHour();
            $this->minute = $this->_getLastMinute();
        }
    }

    //get the due time before current hour
    function _prevHour($arHours, $arDays, $arMonths)
    {
        $this->debug("Going for previous hour");
        $this->hour = array_pop($arHours);
        if ($this->hour === NULL)
        {
            $this->debug("Have to go for previous day");
            $this->_prevDay($arDays, $arMonths);
        }
        else
        {
            $this->minute = $this->_getLastMinute();
        }
    }

    //not used at the moment
    function _getLastMonth()
    {
        $months = $this->_getMonthsArray();
        $month = array_pop($months);

        return $month;
    }

    function _getLastDay($month, $year)
    {
        //put the available days for that month into an array
        $days = $this->_getDaysArray($month, $year);
        $day = array_pop($days);

        return $day;
    }

    function _getLastHour()
    {
        $hours = $this->_getHoursArray();
        $hour = array_pop($hours);

        return $hour;
    }

    function _getLastMinute()
    {
        $minutes = $this->_getMinutesArray();
        $minute = array_pop($minutes);

        return $minute;
    }

    //remove the out of range array elements. $arr should be sorted already and does not contain duplicates
    function _sanitize ($arr, $low, $high)
    {
        $count = count($arr);
        for ($i = 0; $i <= ($count - 1); $i++)
        {
            if ($arr[$i] < $low)
            {
                $this->debug("Remove out of range element. {$arr[$i]} is outside $low - $high");
                unset($arr[$i]);
            }
            else
            {
                break;
            }
        }

        for ($i = ($count - 1); $i >= 0; $i--)
        {
            if ($arr[$i] > $high)
            {
                $this->debug("Remove out of range element. {$arr[$i]} is outside $low - $high");
                unset ($arr[$i]);
            }
            else
            {
                break;
            }
        }

        //re-assign keys
        sort($arr);
        return $arr;
    }

    //given a month/year, list all the days within that month fell into the week days list.
    function _getDaysArray($month, $year = 0)
    {
        if ($year == 0)
        {
            $year = $this->year;
        }

        $days = array();

        //return everyday of the month if both bit[2] and bit[4] are '*'
        if ($this->bits[2] == '*' AND $this->bits[4] == '*')
        {
            $days = $this->getDays($month, $year);
        }
        else
        {
            //create an array for the weekdays
            if ($this->bits[4] == '*')
            {
                for ($i = 0; $i <= 6; $i++)
                {
                    $arWeekdays[] = $i;
                }
            }
            else
            {
                $arWeekdays = $this->expand_ranges($this->bits[4]);
                $arWeekdays = $this->_sanitize($arWeekdays, 0, 7);

                //map 7 to 0, both represents Sunday. Array is sorted already!
                if (in_array(7, $arWeekdays))
                {
                    if (in_array(0, $arWeekdays))
                    {
                        array_pop($arWeekdays);
                    }
                    else
                    {
                        $tmp[] = 0;
                        array_pop($arWeekdays);
                        $arWeekdays = array_merge($tmp, $arWeekdays);
                    }
                }
            }
            $this->debug("Array for the weekdays");
            $this->debug($arWeekdays);

            if ($this->bits[2] == '*')
            {
                $daysmonth = $this->getDays($month, $year);
            }
            else
            {
                $daysmonth = $this->expand_ranges($this->bits[2]);
                // so that we do not end up with 31 of Feb
                $daysinmonth = $this->daysinmonth($month, $year);
                $daysmonth = $this->_sanitize($daysmonth, 1, $daysinmonth);
            }

            //Now match these days with weekdays
            foreach ($daysmonth AS $day)
            {
                $wkday = date('w', mktime(0, 0, 0, $month, $day, $year));
                if (in_array($wkday, $arWeekdays))
                {
                    $days[] = $day;
                }
            }
        }
        $this->debug("Days array matching weekdays for $year-$month");
        $this->debug($days);
        return $days;
    }

    //given a month/year, return an array containing all the days in that month
    function getDays($month, $year)
    {
        $daysinmonth = $this->daysinmonth($month, $year);
        $this->debug("Number of days in $year-$month : $daysinmonth");
        $days = array();
        for ($i = 1; $i <= $daysinmonth; $i++)
        {
            $days[] = $i;
        }
        return $days;
    }

    function _getHoursArray()
    {
        if (empty($this->hours_arr))
        {
            $hours = array();

            if ($this->bits[1] == '*')
            {
                for ($i = 0; $i <= 23; $i++)
                {
                    $hours[] = $i;
                }
            }
            else
            {
                $hours = $this->expand_ranges($this->bits[1]);
                $hours = $this->_sanitize($hours, 0, 23);
            }

            $this->debug("Hour array");
            $this->debug($hours);
            $this->hours_arr = $hours;
        }
        return $this->hours_arr;
    }

    function _getMinutesArray()
    {
        if (empty($this->minutes_arr))
        {
            $minutes = array();

            if ($this->bits[0] == '*')
            {
                for ($i = 0; $i <= 60; $i++)
                {
                    $minutes[] = $i;
                }
            }
            else
            {
                $minutes = $this->expand_ranges($this->bits[0]);
                $minutes = $this->_sanitize($minutes, 0, 59);
            }
            $this->debug("Minutes array");
            $this->debug($minutes);
            $this->minutes_arr = $minutes;
        }
        return $this->minutes_arr;
    }

    function _getMonthsArray()
    {
        if (empty($this->months_arr))
        {
            $months = array();
            if ($this->bits[3] == '*')
            {
                for ($i = 1; $i <= 12; $i++)
                {
                    $months[] = $i;
                }
            }
            else
            {
                $months = $this->expand_ranges($this->bits[3]);
                $months = $this->_sanitize($months, 1, 12);
            }
            $this->debug("Months array");
            $this->debug($months);
            $this->months_arr = $months;
        }
        return $this->months_arr;
    }

}
?>