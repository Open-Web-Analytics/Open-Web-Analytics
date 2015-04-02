<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
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

if (!class_exists('owa_http')) {
	//owa_coreAPI::debug('owa_http already defined');
	require_once(OWA_BASE_DIR.'/owa_httpRequest.php');
}

/**
 * Geolocation plugin for Hostip.info web service
 * 
 * See http://www.hostip.info/use.html for API documentation
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.0.0
 */
class owa_hostip extends owa_location {
	
	/**
	 * URL template for REST based web service
	 *
	 * @var unknown_type
	 */
	var $ws_url = "http://api.hostip.info/get_html.php?ip=%s&position=true";
	
	/**
	 * Constructor
	 *
	 * @return owa_hostip
	 */	
	function __construct() {
		
		return parent::__construct();
	}
	
	/**
	 * Fetches the location from the hostip.info web service
	 *
	 * @param string $ip
	 */
	function get_location( $location_map ) {
		
		$city = '';
		$state = '';
		$country = '';
		$country_code = '';
		$latitude = '';
		$longitude = '';
		
		// check to see if ip is in map
		if ( array_key_exists('ip_address',$location_map) 
			&& ! empty( $location_map['ip_address'] ) 
			&& empty( $location_map['country'] ) ) {
			
			// check to see if ip is valid and not a private address
			if ( filter_var( $location_map['ip_address'], 
							FILTER_VALIDATE_IP, 
							FILTER_FLAG_IPV4 | 
							FILTER_FLAG_NO_PRIV_RANGE ) ) {
			
				// create crawler 
				$crawler = new owa_http;
				$crawler->read_timeout = owa_coreAPI::getSetting('base','ws_timeout');
				// hit web service
				$crawler->fetch(sprintf($this->ws_url, $location_map['ip_address']));
				owa_coreAPI::debug(sprintf("HostIp web service response code: %s", $crawler->crawler->response_code));
				$location = $crawler->crawler->results;
				// replace delimiter
				$location =	str_replace("\n", "|", $location);
				// convert string to array
				$loc_array = explode("|", $location);
				$result = array();
				// convert array to multi dimensional array		
				foreach ($loc_array as $k => $v) {
					
					if (!empty($v)) {
						list($name, $value) = explode(":", $v, 2);	
						$result[$name] = $value;
					}
				}
				
				// parse the city line of response
				if ( isset( $result['City'] ) && ! empty( $result['City'] ) ) {
					// lowercase
					$result['City'] = strtolower($result['City']);
					// explode into array
					$city_array = explode(',', $result['City']);
					// city name is always first
					$city = $city_array[0];
					// if there is a second element then it's a state
					if (isset($city_array[1])) {
						$state = $city_array[1];
					}
				} 
				
				// parse country line of response
				if ( isset( $result['Country'] ) && ! empty( $result['Country'] ) ) {
					//lowercase
					$result['Country'] = strtolower( $result['Country'] );
					// set country	
					$country_parts = explode('(', trim( $result['Country'] ) );
					$country = $country_parts[0];
					// if there is a second element then it's a country code.
					if ( isset($country_parts[1] ) ) {	
						$country_code = substr($country_parts[1],0,-1);
					}
					// debug
					owa_coreAPI::debug('Parse of Hostip country string: '.$result['Country'].' c: '. $country.' cc: '.$country_code);
					
				}
				
				// set latitude
				if ( isset( $result['Latitude'] ) && ! empty( $result['Latitude'] ) ) {
					$latitude = $result['Latitude'];
				}
				// set longitude
				if ( isset( $result['Longitude'] ) && ! empty( $result['Longitude'] ) ) {
					$longitude = $result['Longitude'];
				}
			}
						
			// fail safe checks for empty, unknown or private adddress labels
			// check to make sure values are not "private address" contain "unknown" or "xx"
			if ( empty($city) || strpos( $city, 'private' ) || strpos( $city, 'unknown') ) {
				
				$city = '(not set)';
			}
			// check state
			if ( empty($state) || strpos( $state, 'private' ) || strpos( $state, 'unknown') ) {
		
				$state = '(not set)';
			}
			// check country		
			if ( empty( $country ) 
				|| strpos( $country, 'unknown' ) 
				|| strpos( $country, 'private' ) 
			) {
				$country = '(not set)';
			}
			// check country code
			if ( empty( $country_code ) 
				|| strpos( $country_code, 'xx' ) 
				|| strpos( $country_code, 'unknown' ) 
				|| strpos( $country_code, 'private' ) 
			) {
				$country_code = '(not set)';
			}
				
	       	$location_map['city'] = strtolower(trim($city));
	       	$location_map['state'] =  strtolower(trim($state));
			$location_map['country'] =  strtolower(trim($country));
			$location_map['country_code'] =  strtoupper(trim($country_code));
			$location_map['latitude'] = trim($latitude);
			$location_map['longitude'] = trim($longitude);
			
			// log headers if status is not a 200 
			if ( isset( $crawler->response_code ) && ! strpos( $crawler->response_code, '200' ) ) {
				owa_coreAPI::debug(sprintf("HostIp web service response headers: %s", print_r($crawler->crawler->headers, true)));
			}
		}
		
		return $location_map;
	}
}

?>