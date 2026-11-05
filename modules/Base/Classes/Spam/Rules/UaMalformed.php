<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The user agent is malformed for what it claims:
 *
 *   - a bare word with no `/`, such as `android`. Every product token is
 *     name/version, a library's and an app's as much as a browser's.
 *   - it starts or ends with a quote character, as a header pasted from
 *     source code does: 18 Mac agents beginning with `"` the week before the
 *     4 November attack.
 *   - it claims a browser (Client::claimsBrowser()) without the
 *     `Mozilla/5.0 (` that every current browser's agent begins with.
 *
 * An honest agent that is not a browser does not fire: `MyApp/1.2`, a server
 * library, `curl/8.1`. A declared crawler is not a browser claim either
 * (UA_ROBOT_TOKEN scores it), so Googlebot's agent, which carries Chrome's
 * tokens behind `Mozilla/5.0 AppleWebKit`, does not fire.
 *
 * An empty agent does not fire.
 */
class UaMalformed extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'UA_MALFORMED';
    const SET    = 'request_headers';
    const POINTS = 3.0;

    /** What every current browser's agent begins with. */
    const BROWSER_PREFIX = 'Mozilla/5.0 (';

    /** Quote characters an agent neither starts nor ends with. */
    const QUOTES = array( '"', "'", '`' );

    public function test( $event, array $headers ) {

        $ua = (string) ( $headers['user-agent'] ?? '' );

        if ( $ua === '' ) {

            return false;
        }

        if ( strpos( $ua, '/' ) === false ) {

            return true;
        }

        if ( in_array( $ua[0], self::QUOTES, true ) || in_array( substr( $ua, -1 ), self::QUOTES, true ) ) {

            return true;
        }

        return strpos( $ua, self::BROWSER_PREFIX ) !== 0 && $this->client( $headers )->claimsBrowser();
    }
}

?>
