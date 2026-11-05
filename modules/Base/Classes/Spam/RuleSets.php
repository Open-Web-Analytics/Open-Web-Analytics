<?php
namespace OWA\Module\Base\Classes\Spam;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Which rules run: the rule sets, and the sets each spam_detection level runs.
 *
 * People choose a LEVEL (the spam_detection setting); they are not told about
 * sets. A set groups rules that read the same evidence, so a developer can
 * drop one that misbehaves on their traffic, or a module add its own, through
 * one filter:
 *
 *     CoreAPI::filter( 'spam_rule_sets', $sets, $level )
 *
 * $sets is set name => list of Rule class names, for the level being scored.
 * Whatever comes back is what runs; a set that is not in it runs no rules.
 */
class RuleSets {

    /** The filter point a module adjusts the mapping at. */
    const FILTER = 'spam_rule_sets';

    /** The level a missing or unknown spam_detection value means. */
    const DEFAULT_LEVEL = 'normal';

    /** Every shipped set and its rules. One file per rule, under Rules\. */
    const SETS = array(

        'user_agent' => array(
            Rules\UaRobotToken::class,
            Rules\UaParserSpider::class,
        ),

        'request_headers' => array(
            Rules\NoFetchMetadata::class,
            Rules\NoClientHints::class,
            Rules\ChPlatformMismatch::class,
            Rules\ChInvalidValue::class,
            Rules\ChMobileMismatch::class,
            Rules\UaMalformed::class,
            Rules\AcceptEncodingNotBrowser::class,
            Rules\NoAcceptLanguage::class,
        ),

        'page_origin' => array(
            Rules\GhostPageHost::class,
            Rules\RefererMismatch::class,
            Rules\NoPageLocation::class,
            Rules\NoReferer::class,
        ),

        'query_string' => array(
            Rules\QueryEncodingLoop::class,
            Rules\QueryLong::class,
            Rules\QueryVeryLong::class,
            Rules\QueryAttackPayload::class,
        ),
    );

    /**
     * Each level and the sets it runs.
     *
     * normal is what robot detection did before this: the user agent alone.
     */
    const LEVELS = array(
        'normal' => array( 'user_agent' ),
        'strict' => array( 'user_agent', 'request_headers', 'page_origin', 'query_string' ),
    );

    /** The level spam_detection names, or normal when it names none of them. */
    public static function level() {

        $level = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'spam_detection' );

        return isset( self::LEVELS[ $level ] ) ? $level : self::DEFAULT_LEVEL;
    }

    /**
     * The sets a level runs, after the filter: set name => Rule class names.
     *
     * @param  string $level
     * @return array
     */
    public static function forLevel( $level ) {

        $level = isset( self::LEVELS[ $level ] ) ? (string) $level : self::DEFAULT_LEVEL;

        $sets = array_intersect_key( self::SETS, array_flip( self::LEVELS[ $level ] ) );

        return (array) \OWA\Core\CoreAPI::filter( self::FILTER, $sets, $level );
    }

    /**
     * The rules a level runs, as instances.
     *
     * A class that is not a Rule is skipped with a notice rather than run: a
     * module's mistake here would otherwise stop every beacon.
     *
     * @param  string $level
     * @return Rule[]
     */
    public static function rules( $level ) {

        $rules = array();

        foreach ( self::forLevel( $level ) as $set => $classes ) {

            foreach ( (array) $classes as $class ) {

                if ( ! is_string( $class ) || ! class_exists( $class ) || ! is_subclass_of( $class, Rule::class ) ) {

                    \OWA\Core\CoreAPI::notice( sprintf( 'Spam rule set %s names %s, which is not a spam rule.',
                        (string) $set, is_string( $class ) ? $class : gettype( $class ) ) );

                    continue;
                }

                $rules[] = new $class();
            }
        }

        return $rules;
    }
}

?>
