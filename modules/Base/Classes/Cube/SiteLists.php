<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The site lists attribution is classified by, as SQL tests.
 *
 *   search     conf/searchengines.php   medium organic; Organic / Paid Search
 *   social     conf/socialnetworks.php  Organic / Paid Social
 *   ai         conf/aiassistants.php    medium ai-agent; AI Agent
 *   video      conf/videosites.php      Organic / Paid Video
 *   shopping   conf/shoppingsites.php   Organic / Paid Shopping
 *
 * An entry matches as WHOLE LABELS of the value: `google` matches
 * www.google.com and a tagged utm_source=google, `t.co` matches t.co and
 * x.t.co. Substring containment, which the build used before, also matched
 * `t.co` inside microsoft.com, so every Microsoft referral read as social.
 */
class SiteLists {

    const FILES = array(
        'search'   => array( 'searchengines.php', 'tracking.search_engine_registry' ),
        'social'   => array( 'socialnetworks.php', 'tracking.social_network_registry' ),
        'ai'       => array( 'aiassistants.php', 'tracking.ai_assistant_registry' ),
        'video'    => array( 'videosites.php', 'tracking.video_site_registry' ),
        'shopping' => array( 'shoppingsites.php', 'tracking.shopping_site_registry' ),
    );

    /**
     * A list's entries, lowercased, without duplicates.
     *
     * @param  string $list a key of FILES
     * @return string[]
     */
    public static function entries( $list ) {

        if ( ! isset( self::FILES[ $list ] ) ) {

            throw new \InvalidArgumentException( sprintf( 'no site list "%s"', $list ) );
        }

        $out = array();

        foreach ( (array) \OWA\Core\CoreAPI::loadConf( self::FILES[ $list ][0], self::FILES[ $list ][1] ) as $entry ) {

            $domain = strtolower( trim( (string) ( is_array( $entry ) ? ( $entry['domain'] ?? '' ) : $entry ) ) );

            if ( $domain !== '' ) {

                $out[ $domain ] = true;
            }
        }

        return array_keys( $out );
    }

    /**
     * A test that $value, an already-lowercased SQL expression, is on $list.
     *
     * @param  string  $value
     * @param  string  $list
     * @param  Context $context
     * @return string  a parenthesised boolean; (1 = 0) for an empty list
     */
    public static function matches( $value, $list, Context $context ) {

        $tests = array();

        foreach ( self::entries( $list ) as $entry ) {

            $like = addcslashes( $entry, '\\%_' );

            $tests[] = sprintf( '%1$s = %2$s OR %1$s LIKE %3$s OR %1$s LIKE %4$s OR %1$s LIKE %5$s',
                $value, $context->literal( $entry ), $context->literal( $like . '.%' ),
                $context->literal( '%.' . $like ), $context->literal( '%.' . $like . '.%' ) );
        }

        return $tests ? '(' . implode( ' OR ', $tests ) . ')' : '(1 = 0)';
    }
}

?>
