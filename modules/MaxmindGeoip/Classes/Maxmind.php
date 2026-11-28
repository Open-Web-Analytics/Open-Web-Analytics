<?php
namespace OWA\Module\MaxmindGeoip\Classes;

use MaxMind\Db\Reader;


/**
 * Maxmind Geolocation Wrapper
 * 
 * See http://www.maxmind.com/app/php for API documentation
 */
class Maxmind extends \OWA\Core\Location {

    /** @var string */
    public $db_file_name = 'GeoLite2-City.mmdb';
    /** @var string|null */
    public $db_file_path;
    /** @var bool */
    public $db_file_present = false;

    /** @var string the web service client; a subclass may name another (tests) */
    protected $ws_client_class = WebServiceClient::class;

    /** At most one notice, and one stored failure, per this many seconds. */
    const FAILURE_NOTICE_INTERVAL = 3600;

    /**
     * The GeoLite2 editions this module can read, and what each costs.
     *
     * City is the default and answers city, subdivision and country. Country
     * answers only country, and is a fraction of the size -- a worthwhile trade
     * for an installation whose reports never go below country level.
     *
     * Both are free and both need a licence key to download.
     *
     * ASN is deliberately absent even though it is also free: it carries
     * network data and no location fields at all, so an installation pointed at
     * it would look healthy and silently resolve nothing.
     */
    const EDITIONS = [ 'GeoLite2-City', 'GeoLite2-Country' ];

    /**
     * Which edition this installation reads.
     *
     * The download command consults the same setting, so the file that gets
     * fetched is the file that gets read. They must not be able to disagree:
     * downloading Country while the reader looks for City leaves lookups
     * failing against a database that is sitting right there.
     *
     * @return string
     */
    public static function edition() {

        $configured = (string) \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'db_edition' );

        return in_array( $configured, self::EDITIONS, true ) ? $configured : 'GeoLite2-City';
    }

    /**
     * Where the GeoIP databases are: OWA_MAXMIND_DATA_DIR from owa-config.php,
     * else maxmind/ in the data directory.
     *
     * @return string
     */
    public static function dataDir() {

        return defined( 'OWA_MAXMIND_DATA_DIR' ) && (string) OWA_MAXMIND_DATA_DIR !== ''
            ? \OWA\Core\Env::withSlash( OWA_MAXMIND_DATA_DIR )
            : \OWA\Core\Env::dataDir() . 'maxmind/';
    }

    /**
     * The database file this installation reads: the configured edition's,
     * in dataDir().
     *
     * @return string
     */
    public static function dbFile() {

        return self::dataDir() . self::edition() . '.mmdb';
    }

    /**
     * @return bool
     */
    public function isDbReady() {

        // getLocation() reads city, subdivision and country behind isset()
        // guards, so a Country database simply leaves the finer fields unset
        // rather than failing.
        $this->db_file_name = self::edition() . '.mmdb';

        $this->db_file_path = self::dataDir() . $this->db_file_name;

        if ( file_exists( $this->db_file_path ) ) {

            $this->db_file_present = true;
        } else {

            \OWA\Core\CoreAPI::notice('Maxmind DB file could is not present at: ' . self::dataDir());
        }

        return $this->db_file_present;
    }

    /**
     * Fetches the location from the Maxmind local db
     *
     * @param  array<string, string|null> $location_map
     * @return array<string, string|null>
     */
    public function getLocation( $location_map ) {

        if ( ! $this->isDbReady() ) {

            return $location_map;
        }

        if ( ! array_key_exists( 'ip_address', $location_map ) ) {
            return $location_map;
        }

         $reader = new Reader( $this->db_file_path );

         $record = $reader->get( trim( $location_map['ip_address'] ) );

         $reader->close();

         if ( $record ) {

             $location_map = $this->mapCityRecord( $record, $location_map );
         }

        return $location_map;
    }


    /**
     * Look the IP up through MaxMind's web service rather than the local file.
     *
     * Reached by setting the module's lookup_method to
     * 'geoip_city_isp_org_web_service', with ws_user_name (the MaxMind account
     * id) and ws_license_key. WebServiceClient calls MaxMind's City web service
     * over HTTPS. A lookup that fails hands the location map back untouched, as
     * the local path does when its database is missing.
     *
     * @param  array<string, string|null> $location_map
     * @return array<string, string|null>
     */
    public function getLocationFromWebService($location_map) {

        if ( ! array_key_exists( 'ip_address', $location_map ) ) {
            return $location_map;
        }

        $client_class = $this->ws_client_class;

        if ( ! class_exists( $client_class ) ) {

            \OWA\Core\CoreAPI::notice(
                sprintf( 'The Maxmind web service client %s is not available. Returning no location.', $client_class ) );

            return $location_map;
        }

        $license_key = \OWA\Core\CoreAPI::getSetting('maxmind_geoip', 'ws_license_key');
        $user_name = \OWA\Core\CoreAPI::getSetting('maxmind_geoip', 'ws_user_name');

        /*
         * Wrapped because this one reaches the network on the visitor-facing
         * request. The client throws for an address it has no data for, for a
         * rejected key, and for a timeout, and none of those are a reason to
         * lose the page view.
         */
        try {

            $client = new $client_class( $user_name, $license_key );
            $record = $client->city( trim( $location_map['ip_address'] ) );

        } catch ( \Throwable $e ) {

            self::recordWebServiceFailure( $e->getMessage() );

            return $location_map;
        }

        /*
         * The client answers with a GeoIp2 model, not the array the database
         * reader returns. A model's jsonSerialize() is that same record shape.
         */
        if ( $record instanceof \JsonSerializable ) {

            $record = $record->jsonSerialize();
        }

        if ( $record ) {

            $location_map = $this->mapCityRecord( $record, $location_map );
        }

        return $location_map;
    }

    /**
     * Log a failed web service lookup at notice, and keep it in
     * ws_last_failure for System Health; at most once per
     * FAILURE_NOTICE_INTERVAL, since every lookup fails alike while a key is
     * rejected or the service is down. The others are logged at debug.
     *
     * @param  string   $message the client's
     * @param  int|null $now     unix time
     * @return bool whether it was logged at notice and kept
     */
    public static function recordWebServiceFailure( $message, $now = null ) {

        $now  = $now ?? time();
        $last = (array) \OWA\Core\CoreAPI::getSetting( 'maxmind_geoip', 'ws_last_failure' );

        if ( $now - (int) ( $last['at'] ?? 0 ) < self::FAILURE_NOTICE_INTERVAL ) {

            \OWA\Core\CoreAPI::debug( sprintf( 'Maxmind web service lookup failed: %s', $message ) );

            return false;
        }

        \OWA\Core\CoreAPI::notice( sprintf( 'Maxmind web service lookup failed: %s. No location is recorded while lookups fail; '
            . 'the next failure is logged at notice in an hour.', $message ) );

        $failure = [ 'at' => (int) $now, 'message' => (string) $message ];

        // In memory first, so the rest of this process keeps to the interval even where the save fails.
        \OWA\Core\CoreAPI::setSetting( 'maxmind_geoip', 'ws_last_failure', $failure );

        try {

            \OWA\Core\CoreAPI::persistSetting( 'maxmind_geoip', 'ws_last_failure', $failure );

        } catch ( \Throwable $t ) {

            \OWA\Core\CoreAPI::debug( 'Maxmind: the last web service failure was not saved: ' . $t->getMessage() );
        }

        return true;
    }

    /**
     * Map a MaxMind city record onto OWA's location properties.
     *
     * NO CHARACTER SET CONVERSION HAPPENS HERE, and that is deliberate.
     *
     * The MaxMind DB format spec defines its string type as "a variable length
     * byte sequence that contains valid utf8", and this module reads .mmdb files
     * through MaxMind\Db\Reader exclusively. Converting from Latin-1 would encode
     * UTF-8 a second time: "München" would be stored as "MÃ¼nchen".
     *
     * Nothing downstream needs it. The database connection is utf8mb4, the
     * tables are utf8, and Sanitize::escapeForDisplay() escapes as UTF-8.
     *
     * Rows 1.x stored that way are repaired by the v1 migration
     * (FactMigrator::repairedLocation()).
     *
     * strtolower() is safe over UTF-8 here: since PHP 8.0 it is ASCII-only and
     * locale-insensitive, so multibyte sequences pass through untouched.
     *
     * @param  mixed                     $record the reader's array; the web service's model object is not read
     * @param  array<string, string|null> $location_map
     * @param  string                    $lang
     * @return array<string, string|null>
     */
    private function mapCityRecord( $record, $location_map = [], $lang = 'en' ) {

        if ( $record && is_array( $record ) ) {

            if ( isset( $record['city']['names'][ $lang ] ) ) {

                $location_map['city']             = strtolower( trim( $record['city']['names'][ $lang ] ) );
            }

            if ( isset( $record['continent']['code'] ) ) {

                $location_map['continent']        = strtolower( trim( $record['continent']['code'] ) );
            }

            if ( isset( $record['continent']['names'][ $lang ] ) ) {

                $location_map['continent_code'] = strtolower( trim( $record['continent']['names'][ $lang ] ) );
            }

            if ( isset( $record['subdivisions'][0]['names'][ $lang ]  ) ) {

                $location_map['state']             = strtolower( trim( $record['subdivisions'][0]['names'][ $lang ] ) );
               }

               if ( isset( $record['subdivisions'][0]['iso_code'] ) ) {

                   $location_map['state_code']     = strtolower( trim( $record['subdivisions'][0]['iso_code'] ) );
               }

               if ( isset( $record['country']['names'][ $lang ] ) ) {

                   $location_map['country']         = strtolower( trim( $record['country']['names'][ $lang ] ) );
            }

            if ( isset( $record['country']['iso_code'] ) ) {

                $location_map['country_code']     = strtoupper( trim( $record['country']['iso_code'] ) );
            }

            if ( isset( $record['location']['latitude'] ) ) {

                $location_map['latitude']         = trim( $record['location']['latitude'] );
            }

            if ( isset( $record['location']['longitude'] ) ) {

                $location_map['longitude']         = trim( $record['location']['longitude'] );
            }

            if ( isset( $record['postal']['code'] ) ) {

                $location_map['postal_code']     = trim( $record['postal']['code'] );
            }
        }

        return $location_map;
    }


}
