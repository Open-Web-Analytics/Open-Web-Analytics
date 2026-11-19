<?php
namespace OWA\Core;

/**
 * Geo-location abstract class
 * 
 * Looks up the geographic location of a request based on IP address lookups in a variety of
 * databses or web services.
 */
class Location {

    /**
     * City
     *
     * @var string
     */
    public $city;

    /**
     * Country
     *
     * @var string
     */
    public $country;

    /**
     * Latitude coordinates
     *
     * @var string
     */
    public $latitude;

    /**
     * Longitude coordinates
     *
     * @var string
     */
    public $longitude;

    /**
     * Constructor
     *
     * @return \OWA\Core\Location
     */
    public function __construct() {

    }
}
