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
     * main regex file location
     *
     * @var array
     */
    var $browscap_db;

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

    // DEPRICATED
    function robotCheck() {

        return $this->isRobot();
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

        $dir = \OWA\Core\CoreAPI::getSetting( 'base', 'ua_regexes_dir' ) ?: OWA_DATA_DIR . 'ua-parser/';

        $maintained = $dir . 'regexes.php';

        // Readable, not merely present: an unreadable file here would otherwise
        // take precedence over a bundled copy that works.
        if ( is_readable( $maintained ) ) {

            return $maintained;
        }

        return null;
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
            'WordPress.com mShots',

            // Generic HTTP clients and automation drivers. None of these carry
            // a token the list above matches, and none of them are browsers, so
            // anything arriving under one is a script.
            //
            // Kept deliberately specific. A detected robot is DISCARDED rather
            // than flagged -- see CoreAPI, which aborts the event -- so a false
            // positive costs a real page view. That asymmetry is why these are
            // product names rather than loose words.
            'go-http-client',
            'node-fetch',
            'python-requests',
            'python-urllib',
            'okhttp',
            'axios',
            'guzzlehttp',
            'postmanruntime',
            'scrapy',
            'headlesschrome',
            'phantomjs',
            'puppeteer',
            'playwright',
            'selenium',
            'webdriver',
        );

        $match = false;

        foreach ( $robots as $k => $robot ) {

            // stripos() returns int 0 for a match at the start of the string,
            // which is falsy -- so a truthy check here let any UA *beginning*
            // with a robot token (curl/..., Wget/..., Java/...) pass as human.
            if ( stripos( $this->ua , $robot ) !== false ) {

                \OWA\Core\CoreAPI::debug('Robot detect string found: ' . $robot );

                $match = true;

                break;
            }
        }

        return $match;
    }

    function isRobot() {

        if ( $this->robotRegexCheck() ) {

            return true;
        }

        return $this->parserSaysSpider();
    }

    /**
     * Ask the user-agent parser whether it recognises this as a crawler.
     *
     * A second opinion rather than a replacement: measured against real crawler
     * strings the two disagree in BOTH directions. The token list catches
     * Bytespider, which disguises itself as Android Chrome and which the parser
     * calls a phone; the parser catches WhatsApp, which carries no token any
     * list would think to include. Neither is a superset of the other.
     *
     * The parser's answer comes from its regexes data file, which is bundled
     * with the package and only refreshes when upstream cuts a release -- there
     * has not been one since July 2025. A newer file can be pointed at with the
     * `ua-regexes` setting without waiting for that.
     *
     * Neither of these can identify a headless browser that reports itself as
     * ordinary Chrome. That is not a gap in the data file; it is the limit of
     * asking the client what it is.
     *
     * @return bool
     */
    protected function parserSaysSpider() {

        if ( ! is_object( $this->browser ) || ! isset( $this->browser->device ) ) {

            return false;
        }

        $family = isset( $this->browser->device->family )
            ? (string) $this->browser->device->family
            : '';

        if ( strtolower( $family ) !== 'spider' ) {

            return false;
        }

        \OWA\Core\CoreAPI::debug( 'Robot detected by user agent parser: device family Spider' );

        return true;
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