<?php
namespace OWA\Module\Base\Classes;

use UAParser\Parser;

/**
 * Browscap Class
 * 
 * Used to load and lookup user agents in a local Browscap file
 */

class Browscap extends \OWA\Core\Base {


    /**
     * Browscap Record for current User agent
     *
     * @var \UAParser\Result\Client
     */
    public $browser;

    /**
     * Current user Agent
     *
     * @var string
     */
    public $ua;

    /**
     * @param string $ua
     */
    public function __construct( $ua = '' ) {

        parent::__construct();

        // set user agent
        $this->ua = $ua;

        //lookup UA
        $this->browser = $this->lookup( $this->ua );
        \OWA\Core\CoreAPI::debug('Browser Name : '. $this->getUaFamilyVersion() );

    }

    /**
     * Parse $this->ua; $user_agent is not read.
     *
     * @param  string $user_agent
     * @return \UAParser\Result\Client
     */
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
     *   2. owa-data/conf/ua-parser/regexes.php, an override an admin puts
     *      there (Core\Overrides)
     *   3. nothing, meaning the copy bundled with the library
     *
     * @param  string|null $data_dir a test's; else the installation's
     * @return string|null
     */
    public static function regexesFile( $data_dir = null ) {

        $configured = \OWA\Core\CoreAPI::getSetting( 'base', 'ua-regexes' );

        if ( $configured ) {

            return $configured;
        }

        /*
         * PHP, which the parser includes, and the cron user parses user agents
         * when it drains the queue: run only if nobody but the owner of OWA's
         * files could have written it (Core\Overrides). Readable too: an
         * unreadable file would otherwise take precedence over a bundled copy
         * that works.
         */
        $maintained = \OWA\Core\Overrides::file( \OWA\Core\Overrides::UA_PATTERNS, $data_dir );

        return $maintained !== null && is_readable( $maintained ) ? $maintained : null;
    }

    /**
     * @param  string $name a property of the parse: ua, os, device or originalUserAgent
     * @return mixed that property, a different type for each
     */
    public function get( $name ) {

        return $this->browser->$name;
    }

    /**
     * @return string
     */
    public function getUaFamily() {

        return $this->browser->ua->family;
    }

    /**
     * @return string
     */
    public function getUaFamilyVersion() {

        return $this->browser->ua->toVersion();
    }

    /**
     * @return string
     */
    public function getUaVersion() {

        return $this->browser->ua->toVersion();
    }

    /**
     * @return string
     */
    public function getOsFamily() {

        return $this->browser->os->family;
    }

    /**
     * @return string
     */
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
     * returned here unchanged; deciding what it means is the job of
     * TrackingEventHelpers::resolveDeviceType(),
     * and storing it is not -- absence is NULL in owa_event_raw.
     */

    /**
     * @return string
     */
    public function getDeviceFamily() {

        // Always set: 'Other' when no rule matched.
        return $this->browser->device->family;
    }

    /**
     * @return string
     */
    public function getDeviceBrand() {

        return isset( $this->browser->device->brand )
            ? (string) $this->browser->device->brand
            : '';
    }

    /**
     * @return string
     */
    public function getDeviceModel() {

        return isset( $this->browser->device->model )
            ? (string) $this->browser->device->model
            : '';
    }
}
