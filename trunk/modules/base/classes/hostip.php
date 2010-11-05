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
	function get_location($location_map) {
		
		if (array_key_exists('ip_address',$location_map) && !empty($location_map['ip_address']) && empty($location_map['country'])) {
				
			$crawler = new owa_http;
			$crawler->read_timeout = owa_coreAPI::getSetting('base','ws_timeout');
			
			$crawler->fetch(sprintf($this->ws_url, $location_map['ip_address']));
			owa_coreAPI::debug(sprintf("HostIp web service response code: %s", $crawler->crawler->response_code));
			$location = $crawler->crawler->results;
			//owa_coreAPI::debug(print_r($location,true));
			$location =	str_replace("\n", "|", $location);
				
			$loc_array = explode("|", $location);
			//print_r($loc_array);
			
			$result = array();
					
			foreach ($loc_array as $k => $v) {
				
				if (!empty($v)) {
					list($name, $value) = explode(":", $v, 2);	
					$result[$name] = $value;
				}
			}
			
			if (!empty($result['City'])) {
				
				list ($city, $state) = explode(',', $result['City']);
			} 
			
			if (empty($city) || $city === 'Private Address') {
				
				$city = '(unknown)';
			}
			
			if (empty($state) || $state === 'Private Address') {
		
				$state = '(unknown)';
			}
			
			if (!empty($result['Country'])) {
				list($country, $country_code) = explode('(', trim($result['Country']) );	
				$country_code = substr($country_code,0,-1);
				owa_coreAPI::debug($result['Country'].' c: '. $country.' cc: '.$country_code);
			} else {
				$country = '(unknown)';
				$country_code = '(not set)';
			}
			
			if (empty($country) || strpos($country, 'UNKNOWN COUNTRY') ) {
		
				$country = '(unknown)';
			}
			
			if ($country_code === 'XX') {
				$country_code = '(not set)';
			}
				
	       	$location_map['city'] = strtolower(trim($city));
	       	$location_map['state'] =  strtolower(trim($state));
			$location_map['country'] =  strtolower(trim($country));
			$location_map['country_code'] =  strtoupper(trim($country_code));
			$location_map['latitude'] = trim($result['Latitude']);
			$location_map['longitude'] = trim($result['Longitude']);
			
			// log headers if status is not a 200 
			if (!strpos($crawler->response_code, '200')) {
				owa_coreAPI::debug(sprintf("HostIp web service response headers: %s", print_r($crawler->crawler->headers, true)));
			}
		}
		
		return $location_map;
	}
}

?>