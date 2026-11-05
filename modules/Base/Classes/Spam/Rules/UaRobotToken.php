<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The user agent names a robot, a crawler or an HTTP library.
 *
 * The token list Browscap::robotRegexCheck() held, which logEvent() dropped a
 * beacon on unless log_robots was on. Five points, the default threshold, so
 * `normal` blocks what that check dropped.
 *
 * Kept SPECIFIC. A blocked beacon is not stored, so a false positive costs a
 * real page view; that is why the HTTP clients and automation drivers are named
 * by product rather than by a looser word.
 *
 * Matched anywhere in the agent, case-insensitively, including at position 0:
 * `curl/8.4.0`, `Wget/1.21` and `Java/17` begin with their token.
 */
class UaRobotToken extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'UA_ROBOT_TOKEN';
    const SET    = 'user_agent';
    const POINTS = 5.0;

    const TOKENS = array(
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

        // Generic HTTP clients and automation drivers. None of these carry a
        // token the list above matches, and none of them are browsers.
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

    public function test( $event, array $headers ) {

        return self::matchingToken( (string) ( $headers['user-agent'] ?? '' ) ) !== null;
    }

    /** The first token the agent carries, or null. */
    public static function matchingToken( $ua ) {

        if ( $ua === '' ) {

            return null;
        }

        foreach ( self::TOKENS as $token ) {

            // !== false: stripos() answers 0 for a token at the start.
            if ( stripos( $ua, $token ) !== false ) {

                return $token;
            }
        }

        return null;
    }
}

?>
