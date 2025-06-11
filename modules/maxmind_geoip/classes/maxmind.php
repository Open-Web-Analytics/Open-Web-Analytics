<?php

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

require_once( OWA_BASE_DIR.'/owa_location.php' );

if(!class_exists('\MaxMind\Db\Reader')){
    require_once( OWA_MODULES_DIR . 'maxmind_geoip/includes/MaxMind-DB-Reader-php-1.0.3/src/MaxMind/Db/Reader.php' );
}
if (!class_exists('MaxMind\Db\Reader\Decoder')) {
    require_once( OWA_MODULES_DIR . 'maxmind_geoip/includes/MaxMind-DB-Reader-php-1.0.3/src/MaxMind/Db/Reader/Decoder.php' );
}
if (!class_exists('MaxMind\Db\Reader\InvalidDatabaseException')) {
    require_once( OWA_MODULES_DIR . 'maxmind_geoip/includes/MaxMind-DB-Reader-php-1.0.3/src/MaxMind/Db/Reader/InvalidDatabaseException.php' );
}
if (!class_exists('MaxMind\Db\Reader\Metadata')) {
    require_once( OWA_MODULES_DIR . 'maxmind_geoip/includes/MaxMind-DB-Reader-php-1.0.3/src/MaxMind/Db/Reader/Metadata.php' );
}
if (!class_exists('MaxMind\Db\Reader\Util')) {
    require_once( OWA_MODULES_DIR . 'maxmind_geoip/includes/MaxMind-DB-Reader-php-1.0.3/src/MaxMind/Db/Reader/Util.php' );
}

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
class owa_maxmind extends owa_location {

    /**
     * URL template for REST based web service
     *
     * @var unknown_type
     */
    var $ws_url = '';
    var $db_file_dir;
    var $db_file_name = 'GeoLite2-City.mmdb';
    var $db_file_path;
    var $db_file_present = false;

    /**
     * Constructor
     *
     * @return owa_hostip
     */
    function __construct() {

        return parent::__construct();
    }

    function isDbReady() {

        $this->db_file_path = OWA_MAXMIND_DATA_DIR.$this->db_file_name;

        if ( file_exists( $this->db_file_path ) ) {

            $this->db_file_present = true;
        } else {

            owa_coreAPI::notice('Maxmind DB file could is not present at: ' . OWA_MAXMIND_DATA_DIR);
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

        $license_key = owa_coreAPI::getSetting('maxmind_geoip', 'ws_license_key');
        $user_name = owa_coreAPI::getSetting('maxmind_geoip', 'ws_user_name');

        if ( ! array_key_exists( 'ip_address', $location_map ) ) {
            return $location_map;
        }


        //use GeoIp2\WebService\Client;

        $client = new Client( $user_name, $license_key );

        $record = $client->city( trim( $location_map['ip_address'] ) );


        if ( $record ) {

            $location_map = $this->mapCityRecord( $record, $location_map );
         }

        return $location_map;
    }

    private function mapCityRecord( $record, $location_map = array(), $lang = 'en' ) {

        if ( $record && is_array( $record ) ) {

            if ( isset( $record['city']['names'][ $lang ] ) ) {

                $location_map['city']             = utf8_encode( strtolower( trim( $record['city']['names'][ $lang ] ) ) );
            }

            if ( isset( $record['continent']['code'] ) ) {

                $location_map['continent']        = utf8_encode( strtolower( trim( $record['continent']['code'] ) ) );
            }

            if ( isset( $record['continent']['names'][ $lang ] ) ) {

                $location_map['continent_code'] = utf8_encode( strtolower( trim( $record['continent']['names'][ $lang ] ) ) );
            }

            if ( isset( $record['subdivisions'][0]['names'][ $lang ]  ) ) {

                $location_map['state']             = utf8_encode( strtolower( trim( $record['subdivisions'][0]['names'][ $lang ] ) ) );
               }

               if ( isset( $record['subdivisions'][0]['iso_code'] ) ) {

                   $location_map['state_code']     = utf8_encode( strtolower( trim( $record['subdivisions'][0]['iso_code'] ) ) );
               }

               if ( isset( $record['country']['names'][ $lang ] ) ) {

                   $location_map['country']         = utf8_encode( strtolower( trim( $record['country']['names'][ $lang ] ) ) );
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
