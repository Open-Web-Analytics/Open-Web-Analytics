<?php
namespace OWA\Module\Base\Classes;

/**
 * Date Class
 */


class Date {

    /*
     * Every property is null until set(). The date() parts are strings.
     */

    /** @var int|string|null what set() was given in yyyymmdd form, else date('Ymd') */
    public $yyyymmdd;
    /** @var int|string|false|null what set() was given as a timestamp, else mktime()'s (false on failure) */
    public $timestamp;
    /** @var string|null */
    public $label;
    /** @var string|null */
    public $label_formal;
    /** @var int|null */
    public $year;
    /** @var int|null */
    public $month;
    /** @var int|null */
    public $day;
    /** @var string|null */
    public $day_of_week;
    /** @var string|null */
    public $day_of_week_label;
    /** @var string|null */
    public $day_of_year;
    /** @var string|null */
    public $week_of_year;
    /** @var string|null */
    public $hour;
    /** @var string|null */
    public $minute;
    /** @var string|null */
    public $second;
    /** @var string|null */
    public $microsecond;
    /** @var string|null */
    public $meridiem;
    /** @var string|null */
    public $num_days_in_month;
    /** @var string|null */
    public $utc_offset;

    public function __construct() {

        return;
    }

    /**
     * @param  int|string $date   a timestamp, or yyyymmdd, as $format says
     * @param  string     $format 'yyyymmdd' or 'timestamp'; any other sets only the date() parts
     * @return void
     */
    public function set($date, $format = 'yyyymmdd') {

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

    /**
     * @param  string $name a property
     * @return int|string|false|null that property
     */
    public function get($name){

        return $this->$name;
    }

    /**
     * @return int|null
     */
    public function getMonth() {
        return $this->month;
    }

    /**
     * @param  string $format '' for label, 'formal' for label_formal
     * @return string|null
     */
    public function getLabel($format = '') {

        if (empty($format)) {

            $format = 'label';

        } else {

            $format = 'label_'.$format;
        }

        return $this->$format;
    }

    /**
     * @return int|string|null
     */
    public function getYyyymmdd() {

        return $this->yyyymmdd;
    }

    /**
     * @return int|string|false|null
     */
    public function getTimestamp() {

        return $this->timestamp;
    }

}
