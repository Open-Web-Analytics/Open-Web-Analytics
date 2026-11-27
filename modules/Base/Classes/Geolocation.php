<?php
namespace OWA\Module\Base\Classes;

/**
 * Geolocation Class
 */


class Geolocation {

    /**
     * The current lookup: ip_address, city, country, state, country_code,
     * latitude, longitude; '' for what it could not fill.
     *
     * @var array<string, string|null>
     */
    public $properties = [];

    /** @return Geolocation */
    public static function getInstance() {
        
        return new \OWA\Module\Base\Classes\Geolocation();
    }

    public function __construct() {
    
    }
    
    public function __destruct() {
    
    }
    
    /**
     * Lookups made by this process, by address, oldest first.
     *
     * @var array<string, array<string, string|null>>
     */
    private $byAddress = [];

    /** How many addresses are kept; the oldest is dropped past it. */
    const MAX_CACHED_ADDRESSES = 1000;

    /**
     * Look an address up, or reuse this process's earlier lookup of the same
     * address unless $refresh is true. The result is in $properties.
     *
     * @param  string|null $ip_address
     * @param  bool        $refresh
     * @return void
     */
    public function getGeolocationFromIp($ip_address, $refresh = false) {

        $key = (string) $ip_address;

        if ( $refresh !== true && array_key_exists( $key, $this->byAddress ) ) {

            $this->properties = $this->byAddress[ $key ];

            return;
        }

        $geo = [
            'ip_address'   => $ip_address,
            'city'         => '',
            'country'      => '',
            'state'        => '',
            'country_code' => '',
            'latitude'     => '',
            'longitude'    => '',
        ];

        if ( \OWA\Core\CoreAPI::getSetting( 'base', 'geolocation_lookup' ) ) {

            $geo = \OWA\Core\CoreAPI::getEventDispatch()->filter( 'geolocation', $geo );
        }

        /*
         * A field the lookup could not fill stays empty. The label is the
         * reporting layer's: ResultSetManager::formatDimensionValue() renders an
         * absent dimension as "(not set)" at display time.
         */
        $this->properties = $geo;

        unset( $this->byAddress[ $key ] );
        $this->byAddress[ $key ] = $geo;

        if ( count( $this->byAddress ) > self::MAX_CACHED_ADDRESSES ) {

            unset( $this->byAddress[ array_key_first( $this->byAddress ) ] );
        }
    }

    /**
     * @param  string $name
     * @return string|null|void  nothing for a name not looked up
     */
    public function getProperty($name) {
        
        if (array_key_exists($name, $this->properties)) {
            return $this->properties[$name];
        }
    }
    
    /**
     * @param  string      $name
     * @param  string|null $value
     * @return void
     */
    public function setProperty($name, $value) {
        
        $this->properties[$name] = $value;
    }
    
    /** @return string|null|void  nothing before a lookup */
    public function getCity() {
        
        if (array_key_exists('city', $this->properties)) {
            return $this->properties['city'];
        }
    }
    
    /** @return string|null|void  nothing before a lookup */
    public function getState() {
        if (array_key_exists('state', $this->properties)) {
            return $this->properties['state'];
        }
    }
    
    /** @return string|null|void  nothing before a lookup */
    public function getCountry() {
        if (array_key_exists('country', $this->properties)) {
            return $this->properties['country'];
        }
    }
    
    /** @return string|null|void  nothing before a lookup */
    public function getCountryCode() {
        if (array_key_exists('country_code', $this->properties)) {
            return $this->properties['country_code'];
        }
    }
    
}
