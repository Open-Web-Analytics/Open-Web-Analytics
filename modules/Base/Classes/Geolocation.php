<?php
namespace OWA\Module\Base\Classes;


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


class Geolocation {

    /**
     * The last lookup: ip_address, city, country, state, country_code,
     * latitude, longitude; '' for what it could not fill.
     *
     * @var array<string, string|null>
     */
    public $properties = [];

    /** @return Geolocation */
    public static function getInstance() {
        
        return new \OWA\Module\Base\Classes\Geolocation();
    }

    public function __construct() {
    
    }
    
    public function __destruct() {
    
    }
    
    /**
     * Look an address up, unless one has been already and $refresh is not true.
     *
     * @param  string|null $ip_address
     * @param  bool        $refresh
     * @return void
     */
    public function getGeolocationFromIp($ip_address, $refresh = false) {
        
        if (empty($this->properties) || $refresh === true) {
            
            $geo = ['ip_address'     => $ip_address,
                         'city'         =>  '',
                         'country'         =>  '',
                         'state'        =>  '',
                         'country_code'    =>    '',
                         'latitude'        =>    '',
                         'longitude'    =>    ''];
            
            if ( \OWA\Core\CoreAPI::getSetting( 'base', 'geolocation_lookup' ) ) {
            
                $eq = \OWA\Core\CoreAPI::getEventDispatch();
                $geo = $eq->filter('geolocation', $geo);
            
            }
            
            /*
             * A field the lookup could not fill stays empty.
             *
             * This used to write the literal '(not set)' so that something
             * would appear in a report, which is how owa_location_dim.state
             * came to hold more of that string than real values -- 2,949
             * against 2,099 on the demo install. The label is the reporting
             * layer's job now: ResultSetManager::formatDimensionValue()
             * renders an absent dimension as "(not set)" at display time, so
             * the screen is unchanged and the row stops claiming a value it
             * does not have.
             */
            
            $this->properties = $geo;
        }
    }
    
    /**
     * @param  string $name
     * @return string|null|void  nothing for a name not looked up
     */
    public function getProperty($name) {
        
        if (array_key_exists($name, $this->properties)) {
            return $this->properties[$name];
        }
    }
    
    /**
     * @param  string      $name
     * @param  string|null $value
     * @return void
     */
    public function setProperty($name, $value) {
        
        $this->properties[$name] = $value;
    }
    
    /** @return string|null|void  nothing before a lookup */
    public function getCity() {
        
        if (array_key_exists('city', $this->properties)) {
            return $this->properties['city'];
        }
    }
    
    /** @return string|null|void  nothing before a lookup */
    public function getState() {
        if (array_key_exists('state', $this->properties)) {
            return $this->properties['state'];
        }
    }
    
    /** @return string|null|void  nothing before a lookup */
    public function getCountry() {
        if (array_key_exists('country', $this->properties)) {
            return $this->properties['country'];
        }
    }
    
    /** @return string|null|void  nothing before a lookup */
    public function getCountryCode() {
        if (array_key_exists('country_code', $this->properties)) {
            return $this->properties['country_code'];
        }
    }
    
}
