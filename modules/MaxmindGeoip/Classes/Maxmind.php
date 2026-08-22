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
     * Constructor
     *
     * @return \owa_hostip
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


    function getLocationFromWebService($location_map) {

        $license_key = \OWA\Core\CoreAPI::getSetting('maxmind_geoip', 'ws_license_key');
        $user_name = \OWA\Core\CoreAPI::getSetting('maxmind_geoip', 'ws_user_name');

        if ( ! array_key_exists( 'ip_address', $location_map ) ) {
            return $location_map;
        }


        //use GeoIp2\WebService\Client;

        $client = new \Client( $user_name, $license_key );

        $record = $client->city( trim( $location_map['ip_address'] ) );


        if ( $record ) {

            $location_map = $this->mapCityRecord( $record, $location_map );
         }

        return $location_map;
    }

    private function mapCityRecord( $record, $location_map = array(), $lang = 'en' ) {

        if ( $record && is_array( $record ) ) {

            if ( isset( $record['city']['names'][ $lang ] ) ) {

                $location_map['city']             = $this->latin1ToUtf8( strtolower( trim( $record['city']['names'][ $lang ] ) ) );
            }

            if ( isset( $record['continent']['code'] ) ) {

                $location_map['continent']        = $this->latin1ToUtf8( strtolower( trim( $record['continent']['code'] ) ) );
            }

            if ( isset( $record['continent']['names'][ $lang ] ) ) {

                $location_map['continent_code'] = $this->latin1ToUtf8( strtolower( trim( $record['continent']['names'][ $lang ] ) ) );
            }

            if ( isset( $record['subdivisions'][0]['names'][ $lang ]  ) ) {

                $location_map['state']             = $this->latin1ToUtf8( strtolower( trim( $record['subdivisions'][0]['names'][ $lang ] ) ) );
               }

               if ( isset( $record['subdivisions'][0]['iso_code'] ) ) {

                   $location_map['state_code']     = $this->latin1ToUtf8( strtolower( trim( $record['subdivisions'][0]['iso_code'] ) ) );
               }

               if ( isset( $record['country']['names'][ $lang ] ) ) {

                   $location_map['country']         = $this->latin1ToUtf8( strtolower( trim( $record['country']['names'][ $lang ] ) ) );
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

    /**
     * Convert an ISO-8859-1 (Latin-1) string to UTF-8.
     *
     * MaxMind name fields are Latin-1; the reporting UI expects UTF-8. This was
     * previously done with mb_convert_encoding(), but ext-mbstring is not a
     * production Composer requirement or polyfill (the release build installs
     * with --no-dev), so on hosts without mbstring a GeoIP result containing any
     * of these fields would fatal instead of returning a location. Prefer
     * mbstring when available, then iconv, then a dependency-free byte
     * conversion so the lookup always succeeds.
     */
    private function latin1ToUtf8( $string ) {

        if ( $string === '' || $string === null ) {
            return $string;
        }

        if ( function_exists( 'mb_convert_encoding' ) ) {
            return mb_convert_encoding( $string, 'UTF-8', 'ISO-8859-1' );
        }

        if ( function_exists( 'iconv' ) ) {
            $converted = @iconv( 'ISO-8859-1', 'UTF-8', $string );
            if ( $converted !== false ) {
                return $converted;
            }
        }

        // Pure-PHP fallback: map each Latin-1 byte to its UTF-8 sequence.
        $out = '';
        $len = strlen( $string );
        for ( $i = 0; $i < $len; $i++ ) {
            $c = ord( $string[ $i ] );
            if ( $c < 0x80 ) {
                $out .= $string[ $i ];
            } else {
                $out .= chr( 0xC0 | ( $c >> 6 ) ) . chr( 0x80 | ( $c & 0x3F ) );
            }
        }

        return $out;
    }

}

?>
