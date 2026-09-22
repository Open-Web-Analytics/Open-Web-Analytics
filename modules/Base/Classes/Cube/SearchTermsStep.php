<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * search_terms: the tag if there was one, else what the search engine put in
 * its own query parameter.
 *
 * THE FIRST COMPUTE STEP, and a deliberately cheap one. Pulling a named
 * parameter out of a referring URL and percent-decoding it is a few lines of
 * PHP and not expressible in SQL at all -- MySQL has no URL decode -- so
 * before compute steps existed this fallback had nowhere to go but ingest,
 * which is the layer a corrected engine list can never reach.
 *
 * IT IS WORTH ALMOST NOTHING, MEASURED, and that is fine. Organic-search
 * sessions carrying a real term on peteradamsphoto: 97.1% in 2011, 59.0% by
 * 2013 as Google encrypted search, 0.6% by 2019, and 12 sessions out of 16,083
 * across 2022-2026 -- 0.075%. Only yandex.ru still does it at all, last seen
 * 2026-07-13. The column is built for the mechanism, not the volume: it proves
 * the shared candidate query, the side table, the fill-never-overwrite rule and
 * the cap, so the next one is a registration rather than a design.
 *
 * NO `(not provided)`. v1 returns that literal when a known engine withheld
 * the term, and it is the single most common "search term" on the demo install
 * -- 10,773 of them against 1,530 for the top real one. A sentinel that looks
 * like an observation is exactly what 2.11 refuses; where nothing was
 * collected the column is NULL and the renderer says so once, at the edge.
 */
class SearchTermsStep extends ComputeStep {

    /** @var array|null domain => query param, from the engine list */
    private $engines = null;

    public function reads() {

        return array( 'referer_host', 'referer_query', 'tagged_search_terms' );
    }

    public function when() {

        /*
         * Selective in SQL, not in PHP. "Every referral with a query string"
         * would stream the partition through PHP, having moved the problem
         * rather than solved it.
         */
        return array(
            array( 'tagged_search_terms', 'is null' ),
            array( 'referer_query', 'is not null' ),
            array( 'referer_host', 'is not null' ),
        );
    }

    public function sessionReads() {

        return array( 'tagged_search_terms' );
    }

    public function fallback( Context $context ) {

        return $context->text( Context::SESSION . '.s_tagged_search_terms' );
    }

    public function compute( array $row ) {

        $host  = isset( $row['referer_host'] ) ? (string) $row['referer_host'] : '';
        $query = isset( $row['referer_query'] ) ? (string) $row['referer_query'] : '';

        if ( $host === '' || $query === '' ) {

            return null;
        }

        $param = $this->queryParam( $host );

        if ( $param === null ) {

            return null;
        }

        parse_str( $query, $params );

        if ( ! isset( $params[ $param ] ) || ! is_string( $params[ $param ] ) ) {

            // A known engine that sent no term. v1 writes '(not provided)'
            // here; see the class note for why this does not.
            return null;
        }

        $terms = trim( strtolower( $params[ $param ] ) );

        return $terms === '' ? null : \OWA\Module\Base\Classes\V2Event::strip( $terms );
    }

    /**
     * The query parameter this host's engine uses, or null if it is not one.
     *
     * Matched as containment, the way isSearchEngine() does: the list holds
     * 'google', not a hostname.
     *
     * @param string $host
     * @return string|null
     */
    private function queryParam( $host ) {

        if ( $this->engines === null ) {

            $this->engines = (array) call_user_func(
                array( '\OWA\Module\Base\Classes\TrackingEventHelpers', 'getSearchEngineList' ) );
        }

        foreach ( $this->engines as $engine ) {

            if ( empty( $engine['domain'] ) || empty( $engine['query_param'] ) ) {

                continue;
            }

            if ( stripos( $host, $engine['domain'] ) !== false ) {

                return $engine['query_param'];
            }
        }

        return null;
    }
}

?>
