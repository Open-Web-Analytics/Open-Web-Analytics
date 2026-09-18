<?php
namespace OWA\Module\MaxmindGeoip\Classes;


//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2010 Peter Adams. All rights reserved.
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


use MaxMind\Db\Reader;


if ( ! defined( 'OWA_MAXMIND_DATA_DIR' ) ) {
    define('OWA_MAXMIND_DATA_DIR', OWA_DATA_DIR.'maxmind/');
}

/**
 * Maxmind Geolocation Wrapper
 * 
 * See http://www.maxmind.com/app/php for API documentation
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.4.0
 */
class Maxmind extends \OWA\Core\Location {

    /**
     * URL template for REST based web service
     *
     * @var mixed
     */
    var $ws_url = '';
    var $db_file_dir;
    var $db_file_name = 'GeoLite2-City.mmdb';
    var $db_file_path;
    var $db_file_present = false;

    /**
     * Constructor.
     *
     * The @return it used to carry named owa_hostip, a class that does not
     * exist and never did here -- and a constructor returns nothing to annotate
     * in any case.
     */
    function __construct() {

        return parent::__construct();
    }

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
    const EDITIONS = array( 'GeoLite2-City', 'GeoLite2-Country' );

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

    function isDbReady() {

        // getLocation() reads city, subdivision and country behind isset()
        // guards, so a Country database simply leaves the finer fields unset
        // rather than failing.
        $this->db_file_name = self::edition() . '.mmdb';

        $this->db_file_path = OWA_MAXMIND_DATA_DIR.$this->db_file_name;

        if ( file_exists( $this->db_file_path ) ) {

            $this->db_file_present = true;
        } else {

            \OWA\Core\CoreAPI::notice('Maxmind DB file could is not present at: ' . OWA_MAXMIND_DATA_DIR);
        }

        return $this->db_file_present;
    }

    /**
     * Fetches the location from the Maxmind local db
     *
     * @param string $ip
     */
    function getLocation( $location_map ) {

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
     * 'geoip_city_isp_org_web_service'. That setting has no UI, which is the
     * only reason this has not been noticed: the code below asked for
     * `new \Client(...)` with the `use GeoIp2\WebService\Client` line commented
     * out, so it named a class in the ROOT namespace that has never existed. Any
     * installation that set it got an uncaught Error on every tracked request
     * that reached a geo lookup.
     *
     * The client lives in geoip2/geoip2, which this module does not require --
     * only maxmind-db/reader, for the local file. So the honest behaviour is to
     * say the service is unavailable and hand the location map back untouched,
     * exactly as the local path does when its database is missing. A lookup that
     * cannot happen must not take the page view down with it.
     *
     * Adding the dependency, and a UI for the setting, is a larger decision than
     * a bug fix should make on its own.
     */
    function getLocationFromWebService($location_map) {

        if ( ! array_key_exists( 'ip_address', $location_map ) ) {
            return $location_map;
        }

        $client_class = '\GeoIp2\WebService\Client';

        if ( ! class_exists( $client_class ) ) {

            \OWA\Core\CoreAPI::notice(
                'The Maxmind web service lookup needs the geoip2/geoip2 package, which is not '
              . 'installed. Set the maxmind_geoip lookup_method to city_lite_db to use the local '
              . 'database instead. Returning no location.' );

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

            \OWA\Core\CoreAPI::debug( sprintf(
                'Maxmind web service lookup failed: %s', $e->getMessage() ) );

            return $location_map;
        }

        if ( $record ) {

            $location_map = $this->mapCityRecord( $record, $location_map );
        }

        return $location_map;
    }

    /**
     * Map a MaxMind city record onto OWA's location properties.
     *
     * NO CHARACTER SET CONVERSION HAPPENS HERE, and that is deliberate.
     *
     * Every name below used to be run through a Latin-1 to UTF-8 conversion, on
     * the stated grounds that "MaxMind name fields are Latin-1". They are not.
     * The MaxMind DB format spec defines its string type as "a variable length
     * byte sequence that contains valid utf8", and this module reads .mmdb files
     * through MaxMind\Db\Reader exclusively -- there is no longer any path
     * through the legacy GeoIP .dat API, which did return ISO-8859-1 and is
     * presumably where the belief came from.
     *
     * So the conversion was encoding UTF-8 a second time: "München" was stored
     * as "MÃ¼nchen", and every city or region carrying an umlaut, accent or
     * cedilla has been wrong since. Reported as issue #742 in March 2021. It
     * went unnoticed for so long because the fields that are ISO codes are pure
     * ASCII, so converting them changed nothing, and an English-speaking
     * operator sees no difference either.
     *
     * Nothing downstream needs it. The database connection is utf8mb4, the
     * tables are utf8, and Sanitize::escapeForDisplay() escapes as UTF-8.
     *
     * Rows already stored are repaired by `php cli.php cmd=repair-geo-encoding`.
     *
     * strtolower() is safe over UTF-8 here: since PHP 8.0 it is ASCII-only and
     * locale-insensitive, so multibyte sequences pass through untouched.
     */
    private function mapCityRecord( $record, $location_map = array(), $lang = 'en' ) {

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

?>
