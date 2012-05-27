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

require_once(OWA_BASE_DIR.'/owa_location.php');

if (!class_exists('PEAR_Exception')) {
	set_include_path(get_include_path().':'.OWA_MODULES_DIR.'maxmind_geoip/includes/PEAR-1.9.1/');
}

define('OWA_MAXMIND_DIR', OWA_MODULES_DIR . 'maxmind_geoip/includes/Net_GeoIP-1.0.0/');
		
if (!class_exists('Net_GeoIP')) {
	require_once(OWA_MAXMIND_DIR.'Net/GeoIP.php');
}

set_include_path(
	get_include_path().':'.
	OWA_MODULES_DIR.'maxmind_geoip/includes/Net_GeoIP-1.0.0/'
);

require_once(OWA_MODULES_DIR . 'maxmind_geoip/includes/maxmind-ws/GeoCityLocateIspOrg.class.php');


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
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */
class owa_maxmind extends owa_location {
	
	/**
	 * URL template for REST based web service
	 *
	 * @var unknown_type
	 */
	var $ws_url = '';
	var $db_file_dir;
	var $db_file_name = 'GeoLiteCity.dat';
	var $db_file_path;
	var $db_file_present = false;
	
	/**
	 * Constructor
	 *
	 * @return owa_hostip
	 */	
	function __construct() {
		
		if ( ! defined( 'OWA_MAXMIND_DATA_DIR' ) ) {
			define('OWA_MAXMIND_DATA_DIR', OWA_DATA_DIR.'maxmind/');
		}
		
		$this->db_file_path = OWA_MAXMIND_DATA_DIR.$this->db_file_name;
		
		if ( file_exists( $this->db_file_path ) ) {
			$this->db_file_present = true;
		} else {
			owa_coreAPI::notice('Maxmind DB file could is not present at: ' . OWA_MAXMIND_DATA_DIR);
		}
		
		return parent::__construct();
	}
	
	function isDbReady() {
		
		return $this->db_file_present;
	}
	
	/**
	 * Fetches the location from the Maxmind local db
	 *
	 * @param string $ip
	 */
	function getLocation($location_map) {
		
		if ( ! $this->isDbReady() ) {
			return $location_map;
		}
		
		if ( ! array_key_exists( 'ip_address', $location_map ) ) {
			return $location_map;
		}
		
		// check for shared memory capability
		if ( function_exists( 'shmop_open' ) ) {
			$flag = Net_GeoIP::SHARED_MEMORY ;
		} else {
			$flag = Net_GeoIp::STANDARD ;
		}
		
		$geoip = Net_GeoIP::getInstance($this->db_file_path, $flag);
 		$location = $geoip->lookupLocation(trim($location_map['ip_address']));
 		
 		if ($location) {
 			
 			$location_map['city'] = utf8_encode( strtolower( trim( $location->__get( 'city' ) ) ) );
	       	$location_map['state'] =  utf8_encode( strtolower( trim( $location->__get( 'region' ) ) ) );
			$location_map['country'] =  utf8_encode( strtolower( trim( $location->__get( 'countryName' ) ) ) );
			$location_map['country_code'] =  strtoupper(trim($location->__get('countryCode')));
			$location_map['country_code3'] =  strtoupper(trim($location->__get('countryCode3')));
			$location_map['latitude'] = trim($location->__get('latitude'));
			$location_map['longitude'] = trim($location->__get('longitude'));
			$location_map['dma_code'] = trim($location->__get('dmaCode'));
			$location_map['area_code'] = trim($location->__get('areaCode'));
			$location_map['postal_code'] = trim($location->__get('postalCode'));
	 	}
		
		return $location_map;
	}
	
	
	function getLocationFromWebService($location_map) {
				
		$license_key = owa_coreAPI::getSetting('maxmind_geoip', 'ws_license_key');
		
		if ( ! array_key_exists( 'ip_address', $location_map ) ) {
			return $location_map;
		}
		
		$geoloc = GeoCityLocateIspOrg::getInstance();
		$geoloc->setLicenceKey( $license_key );
		$geoloc->setIP( trim($location_map['ip_address']) );
		
		if ( $geoloc->isError() ) {
			owa_coreAPI::debug( $geoloc->isError().": " . $geoloc->getError() );
			return $location_map;				
		}
		
		$location_map['city'] = utf8_encode( strtolower( trim( $geoloc->getCity() ) ) );
       	$location_map['state'] =  utf8_encode( strtolower( trim($geoloc->getState() ) ) );
		$location_map['country'] =  utf8_encode( strtolower( trim( $geoloc->lookupCountryCode( $geoloc->getCountryCode() ) ) ) );
		$location_map['country_code'] =  strtoupper( trim($geoloc->getCountryCode() ) );
		$location_map['latitude'] = trim( $geoloc->getLat() );
		$location_map['longitude'] = trim( $geoloc->getLong() );
		$location_map['dma_code'] = trim( $geoloc->getMetroCode() );
		$location_map['dma'] = trim( $geoloc->lookupMetroCode( $geoloc->getMetroCode() ) );
		$location_map['area_code'] = trim( $geoloc->getAreaCode() );
		$location_map['postal_code'] = trim( $geoloc->getZip() );
		$location_map['isp'] = utf8_encode( trim( $geoloc->getIsp() ) );
		$location_map['organization'] = utf8_encode( trim( $geoloc->getOrganization() ) );
		$location_map['subcountry_code'] = trim( $geoloc->lookupSubCountryCode( $geoloc->getState(), $geoloc->getCountryCode() ) );
		
		return $location_map;
	}

}

?>