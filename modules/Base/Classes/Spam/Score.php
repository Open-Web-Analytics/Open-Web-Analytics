<?php
namespace OWA\Module\Base\Classes\Spam;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A beacon's spam score, and whether it is blocked.
 *
 * Like a mail server's spam score: each rule the beacon meets adds its points,
 * the score is the plain sum, uncapped, and a beacon whose score reaches the
 * install's spam_threshold is BLOCKED, which means not stored. A blank
 * threshold blocks nothing; the score is still computed and stored.
 *
 * WHERE IT RUNS. admit() is called by Handler\EventRawHandlers::notify() for
 * every beacon that becomes raw rows, before the set of events is built. A
 * module whose events are processed outside tracking.* calls admit() itself.
 * That is after the property stage
 * -- geolocation and the parsed agent are on the event -- and before anything is
 * written, in the request for a direct beacon and in the drain for a queued
 * one. The edge's exclusions (named users, excluded addresses, unknown sites)
 * run first, in logEvent(): they are decided by the request and configuration,
 * they keep what they refuse out of the queue, and what they refuse is not
 * spam, so it is not counted in owa_spam_block.
 */
class Score {

    /** The spam_rules column's width. */
    const RULES_WIDTH = 255;

    /**
     * Score the beacon, stamp the score on it, and say whether to store it.
     *
     * spam_score and spam_rules are set on the event, so every event the set
     * is built from it copies them (TrackingEventHelpers::materialize()). A
     * blocked beacon is counted in owa_spam_block (Blocks::record()).
     *
     * @param  object $event
     * @return bool   false when blocked: write nothing
     */
    public static function admit( $event ) {

        $verdict = self::of( $event, RuleSets::level() );

        $event->set( 'spam_score', $verdict['score'] );
        $event->set( 'spam_rules', $verdict['rules'] );

        if ( ! self::blocks( $verdict['score'], self::threshold() ) ) {

            return true;
        }

        \OWA\Core\CoreAPI::debug( sprintf( 'Not storing %s: spam score %.1f (%s).',
            (string) $event->getEventType(), $verdict['score'], $verdict['rules'] ) );

        Blocks::record( self::day( $event ), (string) $event->getSiteId(), array_keys( $verdict['fired'] ) );

        return false;
    }

    /**
     * What a level's rules make of a beacon.
     *
     * @param  object $event
     * @param  string $level  spam_detection
     * @return array  score (float), fired (rule => points, highest first), rules (spam_rules)
     */
    public static function of( $event, $level ) {

        $headers = self::headers( $event );
        $fired   = array();

        foreach ( RuleSets::rules( $level ) as $rule ) {

            if ( $rule->test( $event, $headers ) ) {

                $fired[ $rule->name() ] = $rule->points();
            }
        }

        $fired = self::ordered( $fired );

        return array(
            'score' => round( array_sum( $fired ), 1 ),
            'fired' => $fired,
            'rules' => self::format( $fired ),
        );
    }

    /**
     * The headers of the beacon request, as the edge captured them onto the
     * event: lower-case names, '' for one the request did not carry.
     *
     * @return array
     */
    public static function headers( $event ) {

        $out = array();

        foreach ( array(
            'user-agent'     => 'HTTP_USER_AGENT',
            'sec-fetch-mode' => 'request_sec_fetch_mode',
            'sec-fetch-site' => 'request_sec_fetch_site',
            'sec-fetch-dest' => 'request_sec_fetch_dest',
            'sec-ch-ua'          => 'request_sec_ch_ua',
            'sec-ch-ua-platform' => 'request_sec_ch_ua_platform',
            'sec-ch-ua-mobile'   => 'request_sec_ch_ua_mobile',
            'accept-encoding'    => 'request_accept_encoding',
            'accept-language'    => 'request_accept_language',
            'referer'            => 'request_referer',
        ) as $header => $property ) {

            $value = $event->get( $property );

            $out[ $header ] = is_string( $value ) ? trim( $value ) : '';
        }

        return $out;
    }

    /** Rule => points, highest points first, then by name so the order is stable. */
    public static function ordered( array $fired ) {

        uksort( $fired, function ( $a, $b ) use ( $fired ) {

            return $fired[ $b ] <=> $fired[ $a ] ?: strcmp( (string) $a, (string) $b );
        } );

        return $fired;
    }

    /**
     * spam_rules: `NAME=points` for each, space-separated, in the order given,
     * as SpamAssassin's tests= header. Cut to whole entries at RULES_WIDTH.
     *
     * @param  array $fired rule => points, already ordered
     * @return string  '' when none fired
     */
    public static function format( array $fired ) {

        $out = '';

        foreach ( $fired as $name => $points ) {

            $entry = sprintf( '%s=%.1f', $name, $points );
            $next  = $out === '' ? $entry : $out . ' ' . $entry;

            if ( strlen( $next ) > self::RULES_WIDTH ) {

                break;
            }

            $out = $next;
        }

        return $out;
    }

    /**
     * The install's spam_threshold, or null for none: blank never blocks.
     *
     * Not a number is also none. A stored value is checked when it is saved
     * (Settings::valueProblem()), so this is a value from owa-config.php, and a
     * blocked beacon is lost where an unblocked one can be deleted.
     *
     * @return float|null
     */
    public static function threshold() {

        $value = \OWA\Core\CoreAPI::getSetting( 'base', 'spam_threshold' );

        if ( is_float( $value ) || is_int( $value ) ) {

            return (float) $value;
        }

        $value = trim( (string) $value );

        if ( $value === '' || ! is_numeric( $value ) ) {

            return null;
        }

        return (float) $value;
    }

    /** Whether a score reaches a threshold. A null threshold blocks nothing. */
    public static function blocks( $score, $threshold ) {

        return $threshold !== null && round( (float) $score, 1 ) >= round( (float) $threshold, 1 );
    }

    /** The beacon's day, derived as raw's is. */
    private static function day( $event ) {

        $yyyymmdd = (int) $event->get( 'yyyymmdd' );

        return $yyyymmdd > 0
            ? $yyyymmdd
            : (int) \OWA\Module\Base\Classes\TrackingEventHelpers::deriveYyyymmdd( null, $event );
    }
}

?>
