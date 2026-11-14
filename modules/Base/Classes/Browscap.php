<?php
namespace OWA\Module\Base\Classes;


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

use UAParser\Parser;

/**
 * Browscap Class
 * 
 * Used to load and lookup user agents in a local Browscap file
 * 
 * @author      Peter Adams <peter@openwebanalytics.com>
 * @copyright   Copyright &copy; 2006 Peter Adams <peter@openwebanalytics.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 * @category    owa
 * @package     owa
 * @version        $Revision$
 * @since        owa 1.0.0
 */

class Browscap extends \OWA\Core\Base {


    /**
     * Browscap Record for current User agent
     *
     * @var mixed
     */
    var $browser;

    /**
     * Current user Agent
     *
     * @var string
     */
    var $ua;
    var $cache;
    var $cacheExpiration;

    function __construct( $ua = '' ) {

        parent::__construct();

        // set user agent
        $this->ua = $ua;

        // init cache
        $this->cache = \OWA\Core\CoreAPI::cacheSingleton();
        $this->cacheExpiration = \OWA\Core\CoreAPI::getSetting('base', 'default_cache_expiration_period');
        $this->cache->setCollectionExpirationPeriod('browscap', $this->cacheExpiration);

        //lookup UA
        $this->browser = $this->lookup( $this->ua );
        \OWA\Core\CoreAPI::debug('Browser Name : '. $this->getUaFamilyVersion() );

    }

    function lookup( $user_agent ) {

        $cap = null;

        \OWA\Core\CoreAPI::profile( $this, __FUNCTION__, __LINE__ );
		\OWA\Core\CoreAPI::debug('looking in cache for browscap');
		
		// check cache
        $cap = $this->cache->get( 'browscap', $this->ua );
        
        if ( $cap ) {
	        
	        return $cap;
	        
        } else {
	        
        	// load parser
            $custom_db = self::regexesFile();

            if ( $custom_db ) {

                $parser = Parser::create($custom_db);

            } else {
	            
                $parser = Parser::create();
            }

            $cap = $parser->parse( $this->ua );

                
	        if ( $cap ) {
	
	            if ( \OWA\Core\CoreAPI::getSetting('base', 'cache_objects') ) {
	
	                $family = $cap->ua->family;
	
	                if (  $family != 'Default Browser' ) {
	
	                    $this->cache->set( 'browscap', $this->ua, $cap, $this->cacheExpiration );
	                }
	            }
	            
	            return $cap;
	        }
	
	    }
    }

    /**
     * The user-agent patterns file to parse with, or null for the bundled one.
     *
     * In order:
     *
     *   1. the `ua-regexes` setting, for an installation that keeps the file
     *      somewhere of its own choosing
     *   2. owa-data/ua-parser/regexes.php, where cmd=update-ua-regexes puts it
     *   3. nothing, meaning the copy bundled with the library
     *
     * The second is the point. The bundled copy is only as new as the PHP
     * library's last release, while the patterns themselves come from uap-core
     * and are updated far more often -- so an installation that refreshes them
     * should get the benefit without also having to configure a path. Same
     * arrangement the Maxmind module uses for its database: a known directory
     * under owa-data, read if it is there.
     *
     * @return string|null
     */
    public static function regexesFile() {

        $configured = \OWA\Core\CoreAPI::getSetting( 'base', 'ua-regexes' );

        if ( $configured ) {

            return $configured;
        }

        $dir = \OWA\Core\CoreAPI::getSetting( 'base', 'ua_regexes_dir' ) ?: \OWA\Core\Env::dataDir() . 'ua-parser/';

        $maintained = $dir . 'regexes.php';

        // Readable, not merely present: an unreadable file here would otherwise
        // take precedence over a bundled copy that works.
        if ( is_readable( $maintained ) ) {

            return $maintained;
        }

        return null;
    }

    function get( $name ) {

        return $this->browser->$name;
    }

    function getUaFamily() {

        return $this->browser->ua->family;
    }

    function getUaFamilyVersion() {

        return $this->browser->ua->toVersion();
    }

    function getUaVersion() {

        return $this->browser->ua->toVersion();
    }

    function getOsFamily() {

        return $this->browser->os->family;
    }

    function getOsVersion() {

        return $this->browser->os->toVersion();
    }

    /*
     * DEVICE. The parser has carried this the whole time -- 633 device rules in
     * the same regexes file the UA and OS rules come from -- and 1.x asks it
     * exactly one question, whether the family is 'Spider'. v2's raw store has
     * columns for the rest, so these three expose what is already parsed rather
     * than adding a parse.
     *
     * 'Other' is the parser's own word for "no rule matched", which is an
     * ANSWER about a desktop browser and an ABSENCE about a phone. It is
     * returned here unchanged; deciding what it means is deviceType()'s job,
     * and storing it is not -- absence is NULL in owa_event_raw.
     */

    function getDeviceFamily() {

        return isset( $this->browser->device->family )
            ? (string) $this->browser->device->family
            : '';
    }

    function getDeviceBrand() {

        return isset( $this->browser->device->brand )
            ? (string) $this->browser->device->brand
            : '';
    }

    function getDeviceModel() {

        return isset( $this->browser->device->model )
            ? (string) $this->browser->device->model
            : '';
    }
}

?>