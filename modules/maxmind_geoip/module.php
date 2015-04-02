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

require_once(OWA_BASE_DIR.'/owa_module.php');

/**
 * Maxmind GeoIP Module
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2010 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version		$Revision$	      
 * @since		owa 1.4.0
 */

class owa_maxmind_geoipModule extends owa_module {
	
	var $method;
	
	function __construct() {
		
		$this->name = 'maxmind_geoip';
		$this->display_name = 'Maxmind GeoIP';
		$this->group = 'geoip';
		$this->author = 'Peter Adams';
		$this->version = '1.0';
		$this->description = 'Performs Maxmind Geo-IP lookups.';
		$this->config_required = false;
		$this->required_schema_version = 1;
		
		$mode = owa_coreAPI::getSetting('maxmind_geoip', 'lookup_method');
		
		switch ( $mode ) {
			
			case "geoip_city_isp_org_web_service":
				$method = 'getLocationFromWebService';
				break;
				
			case "city_lite_db":
				$method = 'getLocation';
				break;
				
			default:
				$method = 'getLocation';
		}
		
		$this->method = $method;
		
		// needed so default filters will not fun
		owa_coreAPI::setSetting('base', 'geolocation_service', 'maxmind');
		
		
		return parent::__construct();
	}
	
	function registerFilters() {
		
		if ( owa_coreAPI::getSetting('base', 'geolocation_service') === 'maxmind' ) {
		
			$this->registerFilter('geolocation', 'maxmind', $this->method, 0, 'classes');
		}
	}
}