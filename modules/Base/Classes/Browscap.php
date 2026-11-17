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
    public $browser;

    /**
     * Current user Agent
     *
     * @var string
     */
    public $ua;

    public function __construct( $ua = '' ) {

        parent::__construct();

        // set user agent
        $this->ua = $ua;

        //lookup UA
        $this->browser = $this->lookup( $this->ua );
        \OWA\Core\CoreAPI::debug('Browser Name : '. $this->getUaFamilyVersion() );

    }

    public function lookup( $user_agent ) {

        /*
         * Parsed every time, not cached.
         *
         * With a persistent cache on, each user agent was kept in a file of
         * its own for a week, and nothing removed a file whose agent never
         * came back -- so anyone sending agents of their own invention to
         * log.php added a file per request, without limit, each one written
         * under the cache's single lock. The parse is what an installation
         * without the File Cache module has always done; a process parses a
         * request's agent once (Service::getBrowscap() keeps the result).
         */

        $custom_db = self::regexesFile();

        $parser = $custom_db ? Parser::create( $custom_db ) : Parser::create();

        return $parser->parse( $this->ua );
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

    public function get( $name ) {

        return $this->browser->$name;
    }

    public function getUaFamily() {

        return $this->browser->ua->family;
    }

    public function getUaFamilyVersion() {

        return $this->browser->ua->toVersion();
    }

    public function getUaVersion() {

        return $this->browser->ua->toVersion();
    }

    public function getOsFamily() {

        return $this->browser->os->family;
    }

    public function getOsVersion() {

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

    public function getDeviceFamily() {

        return isset( $this->browser->device->family )
            ? (string) $this->browser->device->family
            : '';
    }

    public function getDeviceBrand() {

        return isset( $this->browser->device->brand )
            ? (string) $this->browser->device->brand
            : '';
    }

    public function getDeviceModel() {

        return isset( $this->browser->device->model )
            ? (string) $this->browser->device->model
            : '';
    }
}

?>