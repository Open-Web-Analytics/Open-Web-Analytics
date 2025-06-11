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

class owa_browscap extends owa_base {


    /**
     * main regex file location
     *
     * @var array
     */
    var $browscap_db;

    /**
     * Browscap Record for current User agent
     *
     * @var unknown_type
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
        $this->cache = owa_coreAPI::cacheSingleton();
        $this->cacheExpiration = owa_coreAPI::getSetting('base', 'default_cache_expiration_period');
        $this->cache->setCollectionExpirationPeriod('browscap', $this->cacheExpiration);

        //lookup UA
        $this->browser = $this->lookup( $this->ua );
        owa_coreAPI::debug('Browser Name : '. $this->getUaFamilyVersion() );

    }

    // DEPRICATED
    function robotCheck() {

        return $this->isRobot();
    }

    function lookup( $user_agent ) {

        $cap = null;

        owa_coreAPI::profile( $this, __FUNCTION__, __LINE__ );
		owa_coreAPI::debug('looking in cache for browscap');
		
		// check cache
        $cap = $this->cache->get( 'browscap', $this->ua );
        
        if ( $cap ) {
	        
	        return $cap;
	        
        } else {
	        
        	// load parser
            $custom_db = owa_coreAPI::getSetting('base','ua-regexes');

            if ( $custom_db ) {

                $parser = Parser::create($custom_db);

            } else {
	            
                $parser = Parser::create();
            }

            $cap = $parser->parse( $this->ua );

                
	        if ( $cap ) {
	
	            if ( owa_coreAPI::getSetting('base', 'cache_objects') ) {
	
	                $family = $cap->ua->family;
	
	                if (  $family != 'Default Browser' ) {
	
	                    $this->cache->set( 'browscap', $this->ua, $cap, $this->cacheExpiration );
	                }
	            }
	            
	            return $cap;
	        }
	
	    }
    }

    function robotRegexCheck() {

        $robots = array(
            'bot',
            'crawl',
            'spider',
            'curl',
            'host',
            'localhost',
            'java',
            'libcurl',
            'libwww',
            'lwp',
            'perl',
            'php',
            'wget',
            'search',
            'slurp',
            'robot',
            'WordPress.com mShots'
        );

        $match = false;

        foreach ( $robots as $k => $robot ) {

            $match = stripos( $this->ua , $robot );

            if ( $match ) {

                owa_coreAPI::debug('Robot detect string found: ' . $robot );

                break;
            }
        }

        return $match;
    }

    function isRobot() {

        return $this->robotRegexCheck();
    }

    function get( $name ) {

        return $this->browser->$name;
    }

    function getUaFamily() {

        return $this->browser->ua->family;
    }

    function getUaVersionMajor() {

        return $this->browser->ua->major;
    }

    function getUaVersionMinor() {

        return $this->browser->ua->minor;
    }

    function getUaVersionPatch() {

        return $this->browser->ua->patch;
    }

    function getUaFamilyVersion() {

        return $this->browser->ua->toVersion();
    }

    function getUaVersion() {

        return $this->browser->ua->toVersion();
    }

    function getUaOriginal() {

        return $this->browser->originalUserAgent;
    }

    function getUaOs() {

        return $this->browser->toString();
    }

    function getOsFamily() {

        return $this->browser->os->family;
    }

    function getOsVersionMajor() {

        return $this->browser->os->major;
    }

    function getOsVersionMinor() {

        return $this->browser->os->minor;
    }

    function getOsVersionPatch() {

        return $this->browser->os->patch;
    }

    function getOsFamilyVersion() {

        return $this->browser->os->toString();
    }

    function getOsVersion() {

        return $this->browser->os->toVersion();
    }
}

?>