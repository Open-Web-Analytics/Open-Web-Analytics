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
	set_include_path(get_include_path().PATH_SEPARATOR.OWA_MODULES_DIR.'maxmind_geoip/includes/PEAR-1.9.1/');
}

define('OWA_MAXMIND_DIR', OWA_MODULES_DIR.
		'maxmind_geoip'.DIRECTORY_SEPARATOR.
		'includes'.DIRECTORY_SEPARATOR.
		'Net_GeoIP-1.0.0RC3'.DIRECTORY_SEPARATOR);
		
if (!class_exists('Net_GeoIP')) {
	require_once(OWA_MAXMIND_DIR.'Net/GeoIP.php');
}

set_include_path(
	get_include_path().PATH_SEPARATOR.
	OWA_MODULES_DIR.'maxmind_geoip/includes/Net_GeoIP-1.0.0RC3/'
);

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
	var $ws_url;
	var $db_file_dir;
	var $db_file_name = 'GeoLiteCity.dat';
	var $db_file_path;
	
	/**
	 * Constructor
	 *
	 * @return owa_hostip
	 */	
	function __construct() {
		
		if ( ! defined( 'OWA_MAXMIND_DATA_DIR' ) ) {
			define('OWA_MAXMIND_DATA_DIR', OWA_DATA_DIR.'maxmind'.DIRECTORY_SEPARATOR);
		}
		
		$this->db_file_path = OWA_MAXMIND_DATA_DIR.$this->db_file_name;
		owa_coreAPI::debug('hello from maxmind');
		return parent::__construct();
	}
	
	/**
	 * Fetches the location from the Maxmind local db
	 *
	 * @param string $ip
	 */
	function getLocation($location_map) {
		
		// check for shared memory capability
		if ( function_exists( 'shmop_open' ) ) {
			$flag = Net_GeoIP::SHARED_MEMORY ;
		} else {
			$flag = Net_GeoIp::STANDARD ;
		}
		
		$geoip = Net_GeoIP::getInstance($this->db_file_path, $flag);
 		$location = $geoip->lookupLocation($location_map['ip_address']);
 		
 		if ($location) {
 			
 			$location_map['city'] = strtolower(trim($location->__get('city')));
	       	$location_map['state'] =  strtolower(trim($location->__get('region')));
			$location_map['country'] =  strtolower(trim($location->__get('countryName')));
			$location_map['country_code'] =  strtoupper(trim($location->__get('countryCode')));
			$location_map['latitude'] = trim($location->__get('latitude'));
			$location_map['longitude'] = trim($location->__get('longitude'));	
	 	}
		
		return $location_map;
	}
	
	/*
	function getLocationFromWebService($location_map) {
		
		if (array_key_exists('ip_address',$location_map) && !empty($location_map['ip_address']) && empty($location_map['country'])) {
				
			$crawler = new owa_http;
			$crawler->read_timeout = owa_coreAPI::getSetting('base','ws_timeout');
			
			$crawler->fetch(sprintf($this->ws_url, $location_map['ip_address']));
			owa_coreAPI::debug(sprintf("Maxmind web service response code: %s", $crawler->crawler->response_code));
			$location = $crawler->crawler->results;
				
			if ($location) {
 			
	 			$location_map['city'] = strtolower(trim($location['city']));
		       	$location_map['state'] =  strtolower(trim($location['region']));
				$location_map['country'] =  strtolower(trim($location['countryName']));
				$location_map['country_code'] =  strtoupper(trim($location['countryCode']));
				$location_map['latitude'] = trim($location['latitude']);
				$location_map['longitude'] = trim($location['longitude']);	
	 		}
	       	
			// log headers if status is not a 200 
			if (!strpos($crawler->response_code, '200')) {
				owa_coreAPI::debug(sprintf("Maxmind web service response headers: %s", print_r($crawler->crawler->headers, true)));
			}
		}
		
		return $location_map;
	}
*/
}

?>