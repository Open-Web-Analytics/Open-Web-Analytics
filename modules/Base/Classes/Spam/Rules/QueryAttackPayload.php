<?php
namespace OWA\Module\Base\Classes\Spam\Rules;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The page URL or its referrer carries an injection payload: SQL, script,
 * path traversal, or a template or lookup expression.
 *
 * Tested on each URL as the beacon carried it (Spam\Received) and once
 * URL-decoded, since a browser sends `<script` in a URL as `%3Cscript`. Not
 * decoded further: QUERY_ENCODING_LOOP is what notices a value encoded over and
 * over.
 *
 * PATTERNS IS THE ONE LIST, and every entry is specific: a payload this matches
 * is blocked once the score reaches the threshold, so a pattern a real URL can
 * carry costs a real page view. None of these matched a beacon across app's
 * sites on 1-5 November, the attack included.
 */
class QueryAttackPayload extends \OWA\Module\Base\Classes\Spam\Rule {

    const NAME   = 'QUERY_ATTACK_PAYLOAD';
    const SET    = 'query_string';
    const POINTS = 5.0;

    /** family => regex => why it is there. Case-insensitive throughout. */
    const PATTERNS = array(

        'sql' => array(
            // UNION-based extraction. Whitespace or `+` between the words, the
            // two ways a query string carries a space; ALL is optional.
            '/\bunion(?:\s|\+)+(?:all(?:\s|\+)+)?select\b/i' => 'UNION SELECT, appending a query of the attacker\'s',
            // Time-based blind injection: the response delay is the answer.
            '/\bsleep\s*\(/i'                   => 'MySQL SLEEP(), a time-based probe',
            '/\bbenchmark\s*\(/i'               => 'MySQL BENCHMARK(), a time-based probe',
            '/\bwaitfor(?:\s|\+)+delay\b/i'     => 'SQL Server WAITFOR DELAY, a time-based probe',
            // Schema discovery after a working injection.
            '/\binformation_schema\b/i'         => 'the catalogue a probe reads table names from',
            // A quote closing the string, then OR and a comparison of a value
            // with itself: ' or '1'='1, 'or'1'='1, ' or 1=1. The backreference
            // is what keeps "it's or that=this" out.
            '/[\'"](?:\s|\+)*or(?:\s|\+)*[\'"]?(\w+)[\'"]?(?:\s|\+)*=(?:\s|\+)*[\'"]?\1(?!\w)/i'
                                                => 'an always-true condition after a closing quote',
        ),

        'script' => array(
            '/<script/i'          => 'a script element',
            '/javascript:/i'      => 'a javascript: URL',
            '/\bonerror\s*=/i'    => 'an onerror handler, the usual one on an injected image',
            '/\bonload\s*=/i'     => 'an onload handler',
            '/<svg/i'             => 'an svg element, which runs its onload without a script tag',
        ),

        'traversal' => array(
            '#\.\./#'             => 'a parent directory. A browser resolves these out of the path of a page it loads',
            '/%2e%2e%2f/i'        => '../ percent-encoded',
            '#/etc/passwd#i'      => 'the file a Unix traversal probe asks for',
            '/win\.ini/i'         => 'the file a Windows traversal probe asks for',
        ),

        'template' => array(
            '/\$\{jndi:/i'        => 'a Log4j JNDI lookup (Log4Shell)',
            '/\$\{/'              => 'an expression-language or shell substitution',
            '/\{\{[^{}]*\}\}/'    => 'a server-side template expression',
        ),
    );

    public function test( $event, array $headers ) {

        foreach ( array( \OWA\Module\Base\Classes\Spam\Received::pageLocation( $event ),
                         \OWA\Module\Base\Classes\Spam\Received::referrer( $event ) ) as $url ) {

            if ( $url !== '' && ( self::match( $url ) || self::match( urldecode( $url ) ) ) ) {

                return true;
            }
        }

        return false;
    }

    /**
     * The first pattern a text matches, as array( family, regex ), or null.
     *
     * @param  string $text
     * @return array|null
     */
    public static function match( $text ) {

        foreach ( self::PATTERNS as $family => $patterns ) {

            foreach ( array_keys( $patterns ) as $regex ) {

                if ( preg_match( $regex, (string) $text ) ) {

                    return array( $family, $regex );
                }
            }
        }

        return null;
    }
}

?>
