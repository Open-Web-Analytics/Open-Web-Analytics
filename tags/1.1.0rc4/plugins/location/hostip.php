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

require_once(OWA_BASE_DIR.'/owa_httpRequest.php');

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
	function owa_hostip(){
		
		$this->owa_location();
		
		return;
	}
	
	function get_location_xml($ip) {
		
		$url = sprintf($this->ws_url,
						$ip);
						
		$crawler = new owa_http;
		$crawler->fetch($url);
		
		$result = '';
				
		// XML parsing needs to go here.
       		
       	$this->city = trim($result['City'], "\n");
		$this->country = trim($result['Country'], "\n");
		$this->latitude = $result['Latitude'];
		$this->longitude = $result['Longitude'];
		
		//print_r($result);
		
		return;
	
	}
	
	/**
	 * Fetches the location from the hostip.info web service
	 *
	 * @param string $ip
	 */
	function get_location($ip) {
		
		$crawler = new owa_http;
		$crawler->read_timeout = $this->config['ws_timeout'];
		$crawler->fetch(sprintf($this->ws_url, $ip));
		$location = $crawler->results;
				
		$location =	str_replace("\n", "|", $location);
			
		$loc_array = explode("|", $location);
		//print_r($loc_array);
		
		$result = array();
				
		foreach ($loc_array as $k => $v) {
				
			list($name, $value) = split(":", $v, 2);	
			$result[$name] = $value;
		}
				
		//print_r($result);
				
       	$this->city = $result['City'];
		$this->country = trim($result['Country']);
		$this->latitude = trim($result['Latitude']);
		$this->longitude = trim($result['Longitude']);
		
		$this->e->debug(sprintf("HostIp web service response code: %s", $crawler->response_code));
		
		// log headers if status is not a 200 
		if (strstr($crawler->response_code, "200") === false):
			$this->e->debug(sprintf("HostIp web service response headers: %s", print_r($crawler->headers, true)));
		endif;
		
		return;
	}
	
}


?>