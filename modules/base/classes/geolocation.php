<?php 

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2008 Peter Adams. All rights reserved.
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
 * Geolocation Class
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2008 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$          
 * @since        owa 1.3.0
 */


class owa_geolocation {

    var $properties = array();
    
    public static function getInstance() {
        
        return new owa_geolocation();
    }

    function __construct() {
    
    }
    
    function __destruct() {
    
    }
    
    function getGeolocationFromIp($ip_address, $refresh = false) {
        
        if (empty($this->properties) || $refresh === true) {
            
            $geo = array('ip_address'     => $ip_address, 
                         'city'         =>  '',
                         'country'         =>  '',
                         'state'        =>  '',
                         'country_code'    =>    '',
                         'latitude'        =>    '',
                         'longitude'    =>    '');
            
            if ( owa_coreAPI::getSetting( 'base', 'geolocation_lookup' ) ) {
            
                $eq = owa_coreAPI::getEventDispatch();
                $geo = $eq->filter('geolocation', $geo);
            
            }
            
            foreach ($geo as $k => $v) {
                if ( ! $v ) {
                    $geo[$k] = '(not set)';
                }
            }
            
            $this->properties = $geo;
        }
    }
    
    function getProperty($name) {
        
        if (array_key_exists($name, $this->properties)) {
            return $this->properties[$name];
        }
    }
    
    function setProperty($name, $value) {
        
        $this->properties[$name] = $value;
    }    
    
    function getCity() {
        
        if (array_key_exists('city', $this->properties)) {
            return $this->properties['city'];
        }
    }
    
    function getState() {
        if (array_key_exists('state', $this->properties)) {
            return $this->properties['state'];
        }
    }
    
    function getCountry() {
        if (array_key_exists('country', $this->properties)) {
            return $this->properties['country'];
        }
    }
    
    function getCountryCode() {
        if (array_key_exists('country_code', $this->properties)) {
            return $this->properties['country_code'];
        }
    }
    
    function getLatitude() {
        if (array_key_exists('latitude', $this->properties)) {
            return $this->properties['latitude'];
        }
    }
    
    function getLongitude() {
        if (array_key_exists('longitude', $this->properties)) {
            return $this->properties['longitude'];
        }
    }
    
    function generateId($country = '', $state = '', $city = '') {
        
        if ( ! $country ) {
        
            $country = $this->getCountry();
        }
        
        if ( ! $state ) {
            
            $state = $this->getState();
        }
        
        if ( ! $city ) {
        
            $city = $this->getCity();
        }
        $id_string = trim( strtolower($country)) . trim( strtolower($state)) . trim( strtolower($city));
        return owa_lib::setStringGuid( $id_string );
        
    }
}

?>