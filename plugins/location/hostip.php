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

/**
 * Geolocation plugin for Hostip.info web service
 * 
 * See http://www.hostip.info/use.html for API documentation
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    wa
 * @package     wa
 * @version		$Revision$	      
 * @since		wa 1.0.0
 */
class wa_hostip extends wa_location {
	
	/**
	 * Constructor
	 *
	 * @return wa_hostip
	 */
	function wa_hostip(){
		
		$this->wa_location();
		
		return;
	}
	
	/**
	 * Fetches the location from the hostip.info web service
	 *
	 * @param string $ip
	 */
	function get_location($ip) {
		
		$url = "http://api.hostip.info/get_html.php?ip=".$ip."&position=true";
		
		$url = parse_url($url);

		if(!in_array($url['scheme'],array('','http')))
			return;

		$fp = fsockopen ($url['host'], ($url['port'] > 0 ? $url['port'] : 80), $errno, $errstr, $timeout);
			
		if (!$fp):
       		return;
       		// echo "$errstr ($errno)<br>\n";
   
  		else:
			fputs ($fp, "GET ".$url['path'].($url['query'] ? '?'.$url['query'] : '')." HTTP/1.0\r\nHost: ".$url['host']."\r\n\r\n");
			$location = array();

			while (!feof($fp)) {
				
				// Read row
				$buffer = fgets($fp, 14096); // big enough?
				//print $buffer;	
				// Parse the row
				
				list($name, $value) = split(":", $buffer, 2);
				
				$result[$name] = $value;
				
       		}
       		
       			$this->city = $result['City'];
				$this->country = $result['Country'];
				$this->latitude = $result['Latitude'];
				$this->longitude = $result['Longitude'];
  	    	
			fclose ($fp);
		endif;
		
		return;
	}
	
}


?>