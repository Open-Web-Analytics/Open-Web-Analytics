<?php
namespace OWA\Module\Base\Classes;


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


class TimePeriod {

    var $period;
    var $startDate;
    var $endDate;
    var $label;
    var $diff_years;
    var $diff_months;
    var $diff_days;
    var $is_default_period = false;

    /** The timestamp a relative period is counted from; null is the clock. */
    var $now = null;

    function __construct() {

        //parent::__construct();

        $this->startDate = \OWA\Core\CoreAPI::supportClassFactory('base', 'date');
        $this->endDate = \OWA\Core\CoreAPI::supportClassFactory('base', 'date');
    }

    /**
     * Periods the picker no longer offers.
     *
     * Comparison replaced them: "Same month last year" is This month compared
     * to the previous year. A request naming one is treated as naming no
     * period, so an old bookmark opens on the default period rather than an
     * error page.
     */
    const RETIRED_PERIODS = array( 'same_day_last_week', 'same_week_last_year', 'same_month_last_year' );

    /** The period used when the default_reporting_period setting names one that is not offered. */
    const FALLBACK_PERIOD = 'last_seven_days';

    /**
     * The comparisons the picker offers, keyed by the `compare` request value,
     * in its order; the first is what Compare starts on. `date_range` is a
     * comparison with its own compareStartDate and compareEndDate, the way
     * `period=date_range` is.
     */
    const COMPARE_PERIODS = array(
        'previous_period'     => 'Previous period',
        'previous_period_dow' => 'Previous period (same weekdays)',
        'previous_year'       => 'Previous year',
        'previous_year_dow'   => 'Previous year (same weekdays)',
    );

    public static function isRetired( $period ) {

        return in_array( (string) $period, self::RETIRED_PERIODS, true );
    }

    function getDefaultReportingPeriod() {

        $period = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'default_reporting_period' );

        return $this->isValid( $period ) && $period !== 'date_range' ? $period : self::FALLBACK_PERIOD;
    }

    /**
     * Every value `compare` may take.
     *
     * @return array<int, string>
     */
    public static function getValidComparePeriods() {

        return array_merge( array_keys( self::COMPARE_PERIODS ), array( 'date_range' ) );
    }

    /**
     * The dates a comparison covers, for a period from $start to $end.
     *
     * Plain calendar arithmetic on yyyymmdd dates, done in UTC so no clock
     * change can move a day. The picker does the same arithmetic in the
     * browser to preview the range; tests/fixtures/compare-ranges.json holds
     * cases both are tested against.
     *
     * - previous_period: the same number of days, immediately before.
     * - previous_period_dow: moved back by the length rounded up to whole
     *   weeks, so each day lines up with the same weekday.
     * - previous_year: the same dates a year earlier; 29 February becomes the
     *   28th.
     * - previous_year_dow: 364 days earlier, the same weekdays.
     *
     * @param string $key   a key of COMPARE_PERIODS
     * @param string $start yyyymmdd
     * @param string $end   yyyymmdd
     * @return array{0: string, 1: string}|null [start, end] as yyyymmdd, or null for an unknown key
     */
    public static function compareRange( $key, $start, $end ) {

        $utc = new \DateTimeZone( 'UTC' );
        $s   = \DateTimeImmutable::createFromFormat( '!Ymd', (string) $start, $utc );
        $e   = \DateTimeImmutable::createFromFormat( '!Ymd', (string) $end, $utc );

        if ( ! $s || ! $e ) {

            return null;
        }

        $days = (int) $s->diff( $e )->days + 1;

        switch ( $key ) {

            case 'previous_period':
                $back = $days;
                break;

            case 'previous_period_dow':
                $back = (int) ceil( $days / 7 ) * 7;
                break;

            case 'previous_year_dow':
                $back = 364;
                break;

            case 'previous_year':
                $year = function ( \DateTimeImmutable $d ) use ( $utc ) {
                    $y = (int) $d->format( 'Y' ) - 1;
                    $m = (int) $d->format( 'n' );
                    $last = (int) ( new \DateTimeImmutable( sprintf( '%04d-%02d-01', $y, $m ), $utc ) )->format( 't' );
                    return sprintf( '%04d%02d%02d', $y, $m, min( (int) $d->format( 'j' ), $last ) );
                };
                return array( $year( $s ), $year( $e ) );

            default:
                return null;
        }

        return array(
            $s->modify( "-$back days" )->format( 'Ymd' ),
            $e->modify( "-$back days" )->format( 'Ymd' ),
        );
    }

    /**
     * The predefined periods with the dates each covers now, in the order the
     * picker lists them. The picker shows these dates before anything is
     * applied, and they are cut here, in the installation's timezone, so the
     * browser never has to work out what today is.
     *
     * @return array<int, array{key: string, label: string, startDate: string, endDate: string}>
     */
    function getPresets() {

        $out = array();

        foreach ( $this->getPeriodLabels() as $key => $p ) {

            $tp = new self();
            $tp->now = $this->now;
            $tp->set( $key );

            $out[] = array(
                'key'       => $key,
                'label'     => $p['label'],
                'startDate' => $tp->getStartDate()->getYyyymmdd(),
                'endDate'   => $tp->getEndDate()->getYyyymmdd(),
            );
        }

        return $out;
    }

    /**
     * Reduce a request map to just the keys this class reads, and guarantee
     * every one of them is present.
     *
     * The filtering half of this was already here; the defaults were built and
     * then thrown away, so a caller who omitted a key -- ?period=date_range with
     * no dates, say -- reached a read of a key that was not there. That is a
     * warning per read, and PHP warnings are failures under CI.
     *
     * An absent date reads as '' rather than false so it stays a string all the
     * way to sscanf(). Both are empty(), so the branching below is unchanged.
     */
    private static function normalizeMap( $map ) {

        $keys = array(
            'period'    => '',
            'startDate' => '',
            'endDate'   => '',
            // Times are timestamps, not yyyymmdd strings, and they are handed
            // straight to Date::set($ts, 'timestamp') -- so their empty value is
            // null, which that signature accepts, and not ''.
            'startTime' => null,
            'endTime'   => null
        );

        return array_merge( $keys, array_intersect_key( (array) $map, $keys ) );
    }

    /**
     * Why a request map does not describe a usable date range, or '' if it does.
     *
     * A range is both bounds or neither. One bound alone is not a range: an end
     * date on its own resolved its missing start to today, so asking for
     * "up to the 10th" produced a window running from today BACKWARDS to the
     * 10th -- inverted, and silently so.
     *
     * Only asked when a range is what the request is actually for: an explicit
     * period=date_range, or dates with no period at all. A named relative
     * period wins over any dates sent with it, so partial dates alongside
     * 'today' are ignored rather than refused.
     *
     * Equal bounds are legal and deliberate -- a single day is a range whose
     * ends are equal, which is how ReportVisitorsRoster asks for one.
     *
     * Static and free of settings so both the web and REST paths can ask
     * before building anything, and so it holds without a database.
     *
     * @param array $map request parameters
     * @return string a user-facing reason, or '' when the map is usable
     */
    public static function getRangeError( $map ) {

        $map    = self::normalizeMap( $map );
        $period = trim( (string) $map['period'] );
        $start  = trim( (string) $map['startDate'] );
        $end    = trim( (string) $map['endDate'] );

        $asksForRange = ( $period === 'date_range' )
                     || ( $period === '' && ( $start !== '' || $end !== '' ) );

        if ( ! $asksForRange ) {

            return '';
        }

        if ( $start === '' && $end === '' ) {

            return 'A date range needs a start date and an end date.';
        }

        if ( $start === '' ) {

            return 'A date range needs a start date. An end date on its own does not describe a range.';
        }

        if ( $end === '' ) {

            return 'A date range needs an end date. A start date on its own does not describe a range.';
        }

        // Both bounds are compared as yyyymmdd, where string order and
        // chronological order agree -- but only for eight digits. Anything else
        // would make the comparison below meaningless rather than false.
        foreach ( array( 'start date' => $start, 'end date' => $end ) as $label => $value ) {

            if ( ! preg_match( '/^\d{8}$/', $value ) ) {

                return sprintf( 'The %s "%s" is not a date. Dates are yyyymmdd.',
                    $label, htmlspecialchars( $value, ENT_QUOTES ) );
            }
        }

        if ( $start > $end ) {

            return sprintf( 'The start date (%s) is after the end date (%s).', $start, $end );
        }

        return '';
    }

    /**
     * Why a request's comparison is not usable, or '' if it is (or there is none).
     *
     * `compare` names one of COMPARE_PERIODS, or `date_range` with
     * compareStartDate and compareEndDate. Two compare dates and no `compare`
     * mean date_range, as two dates and no `period` do.
     *
     * @param array $map request parameters
     * @return string a user-facing reason, or ''
     */
    public static function getCompareError( $map ) {

        $map     = (array) $map;
        $compare = trim( (string) ( $map['compare'] ?? '' ) );
        $start   = trim( (string) ( $map['compareStartDate'] ?? '' ) );
        $end     = trim( (string) ( $map['compareEndDate'] ?? '' ) );

        if ( $compare === '' && $start === '' && $end === '' ) {

            return '';
        }

        if ( $compare !== '' && $compare !== 'date_range' ) {

            return in_array( $compare, self::getValidComparePeriods(), true ) ? ''
                : sprintf( '"%s" is not a comparison.', htmlspecialchars( $compare, ENT_QUOTES ) );
        }

        $error = self::getRangeError( array( 'period' => 'date_range', 'startDate' => $start, 'endDate' => $end ) );

        return $error === '' ? '' : 'Compared to: ' . $error;
    }

    /**
     * The comparison a request asks for, resolved against this period's dates.
     *
     * @param array $map request parameters
     * @return array{compare: string, startDate: string, endDate: string}|null null when there is none
     */
    function getCompareFromMap( $map ) {

        $map = (array) $map;

        if ( self::getCompareError( $map ) !== '' ) {

            return null;
        }

        $compare = trim( (string) ( $map['compare'] ?? '' ) );
        $start   = trim( (string) ( $map['compareStartDate'] ?? '' ) );

        if ( $compare === '' && $start === '' ) {

            return null;
        }

        if ( $compare === '' || $compare === 'date_range' ) {

            return array( 'compare' => 'date_range', 'startDate' => $start, 'endDate' => trim( (string) $map['compareEndDate'] ) );
        }

        $r = self::compareRange( $compare, $this->startDate->getYyyymmdd(), $this->endDate->getYyyymmdd() );

        return $r ? array( 'compare' => $compare, 'startDate' => $r[0], 'endDate' => $r[1] ) : null;
    }

    function setFromMap( $map ) {

        $map = self::normalizeMap( $map );


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
            \OWA\Core\CoreAPI::debug("$period is not a valid period. Defaulting to default.");
        }

        $this->_setDates( $map );
        $this->_setLabel( $period );
        $this->_setDifferences();

    }

    // checks to see if the period value passsed is valid.
    /**
     * Every period a request may name.
     *
     * One list, because there were two: this class accepted the picker's labels
     * plus date_range, while the REST controller built its own from the labels
     * alone. The same request was therefore valid over the web and rejected
     * over the API -- `period=date_range` -- for no reason anyone chose.
     *
     * date_range belongs here and not in getPeriodLabels() because that list is
     * the DROPDOWN: a custom range is not an entry in it, it is what the
     * calendar produces.
     *
     * Naming it stays optional. Two dates and no period infer date_range (see
     * setFromMap), which is the form the API has always taken and the one that
     * should not need a redundant parameter to keep working.
     *
     * @return array<int, string>
     */
    function getValidPeriods() {

        $valid = array_keys( $this->getPeriodLabels() );
        $valid[] = 'date_range';

        return $valid;
    }

    function isValid( $value ) {

        return in_array( $value, $this->getValidPeriods(), true );
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
     * The predefined periods, in the order the picker offers them: each one's
     * label, and the days it covers.
     *
     * @return array
     */
    function getPeriodLabels() {

        return array(

                    'today'            => array('label' => \OWA\Core\CoreAPI::t( 'Today' ), 'covers' => 'Today'),
                    'yesterday'        => array('label' => \OWA\Core\CoreAPI::t( 'Yesterday' ), 'covers' => 'Yesterday'),
                    'this_week'        => array('label' => \OWA\Core\CoreAPI::t( 'This week (Sun – today)' ), 'covers' => 'Sunday of the current week to today'),
                    'last_seven_days'  => array('label' => \OWA\Core\CoreAPI::t( 'Last 7 days' ), 'covers' => 'Today and the six days before it'),
                    'last_week'        => array('label' => \OWA\Core\CoreAPI::t( 'Last week (Sun – Sat)' ), 'covers' => 'Sunday to Saturday of the previous week'),
                    'last_28_days'     => array('label' => \OWA\Core\CoreAPI::t( 'Last 28 days' ), 'covers' => 'Today and the 27 days before it'),
                    'last_thirty_days' => array('label' => \OWA\Core\CoreAPI::t( 'Last 30 days' ), 'covers' => 'Today and the 29 days before it'),
                    'this_month'       => array('label' => \OWA\Core\CoreAPI::t( 'This month' ), 'covers' => 'The 1st of the current month to today'),
                    'last_month'       => array('label' => \OWA\Core\CoreAPI::t( 'Last month' ), 'covers' => 'The previous calendar month'),
                    'last_90_days'     => array('label' => \OWA\Core\CoreAPI::t( 'Last 90 days' ), 'covers' => 'Today and the 89 days before it'),
                    'quarter_to_date'  => array('label' => \OWA\Core\CoreAPI::t( 'Quarter to date' ), 'covers' => 'The first day of the current quarter to today'),
                    'this_year'        => array('label' => \OWA\Core\CoreAPI::t( 'This year (Jan – today)' ), 'covers' => '1 January of the current year to today'),
                    'last_year'        => array('label' => \OWA\Core\CoreAPI::t( 'Last calendar year' ), 'covers' => 'The previous calendar year'),
        );

    }

    function _setDates($map = array()) {

        // set() reaches here without going through setFromMap, so normalize again.
        $map = self::normalizeMap( $map );

        $time_now = \OWA\Core\Lib::time_now( $this->now );
        $start = '';
        $end = '';

        /*
         * The start (00:00:00) or end (23:59:59) of the day $offset days from
         * today. Counted in calendar days by mktime(),
         * never in seconds: a day is 23 or 25 hours long when the clocks
         * change, and 7 x 86400 seconds back from the end of today is a
         * different date on those nights.
         */
        $day = function ( $offset, $end = false ) use ( $time_now ) {

            return $end
                ? mktime( 23, 59, 59, $time_now['month'], $time_now['day'] + $offset, $time_now['year'] )
                : mktime( 0, 0, 0, $time_now['month'], $time_now['day'] + $offset, $time_now['year'] );
        };

        switch ($this->period) {

            case "today":

                $start = $day( 0 );
                $end = $day( 0, true );
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
                $start = $day( -6 );
                $end = $day( 0, true );
                break;

            /*
             * The "this ..." periods end TODAY, not on the last day of the
             * week, month or year: the days after today have no data, and a
             * trend drew them as a flat line at zero.
             */
            case "this_week":
                $start = $day( - $time_now['dayofweek'] );
                $end = $day( 0, true );
                break;

            case "this_month":
                $start = mktime(0, 0, 0, $time_now['month'], 1 , $time_now['year']);
                $end = $day( 0, true );
                break;

            case "this_year":
                $start = mktime(0, 0, 0, 1, 1, $time_now['year']);
                $end = $day( 0, true );
                break;

            case "quarter_to_date":
                $start = mktime(0, 0, 0, (int) ( floor( ( $time_now['month'] - 1 ) / 3 ) * 3 ) + 1, 1, $time_now['year']);
                $end = $day( 0, true );
                break;

            case "last_28_days":
                $start = $day( -27 );
                $end = $day( 0, true );
                break;

            case "last_90_days":
                $start = $day( -89 );
                $end = $day( 0, true );
                break;

            case "yesterday":
                $start = $day( -1 );
                $end = $day( -1, true );
                break;

            case "last_week":
                $start = $day( - $time_now['dayofweek'] - 7 );
                $end = $day( - $time_now['dayofweek'] - 1, true );
                break;

            case "last_month":
                $month =  $time_now['month'] - 1;
                $start = mktime(0, 0, 0, $month, 1, $time_now['year']);
                $last = \OWA\Core\CoreAPI::supportClassFactory('base', 'date');
                $last->set($start, 'timestamp');
                $end = mktime(23, 59, 59, $last->get('month'), $last->get('num_days_in_month'), $last->get('year'));
                break;

            case "last_year":
                $year = $time_now['year'] - 1;
                $start = mktime(0, 0, 0, 1, 1, $year);
                $end = mktime(23, 59, 59, 12, 31, $year);
                break;


            /*
             * "all_time" is gone. It set the start to 1 January 1969 and the
             * end to now, which is a full scan of every fact table on the
             * installation -- across every partition, by construction, since
             * the range cannot be pruned to any of them.
             *
             * It was already unreachable through the normal path: isValid() is
             * built from the picker's labels plus date_range, so a request for
             * it silently became the default reporting period. Removing the
             * case makes that refusal honest instead of accidental, and stops
             * set() -- which has no such guard -- from being able to ask for it
             * directly.
             *
             * The sub-hour periods (last_hour, last_half_hour, last_24_hours)
             * remain implemented and unoffered. They need bound pruning that
             * works at finer than day granularity before they would be
             * anything but expensive, and nothing needs them today.
             */

            case "last_thirty_days":
                $start = $day( -29 );
                $end = $day( 0, true );
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

        // The hour it is now, as dateHour spells it and in the zone it reads
        // in, so a trend by hour of today stops at the hour it has reached.
        $info['currentHour'] = (int) date( 'YmdH' );

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
