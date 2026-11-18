<?php
namespace OWA\Module\Base\Classes\Cube;

/**
 * search_terms: the tag if there was one, else what the search engine put in
 * its own query parameter.
 *
 * THE FIRST COMPUTE STEP, and a deliberately cheap one. Pulling a named
 * parameter out of a referring URL and percent-decoding it is a few lines of
 * PHP and not expressible in SQL at all -- MySQL has no URL decode -- and
 * here, unlike at ingest, a corrected engine list reaches history.
 *
 * Few organic-search sessions carry a real term now that the major engines
 * encrypt search. The column is built for the mechanism, not the volume: the
 * shared candidate query, the side table, the fill-never-overwrite rule and
 * the cap.
 *
 * NO `(not provided)`, the literal v1 returns when a known engine withheld the
 * term. A sentinel that looks like an observation is what 2.11 refuses; where nothing was
 * collected the column is NULL and the renderer says so once, at the edge.
 */
class SearchTermsStep extends ComputeStep {

    /** @var array<array{domain?: string, query_param?: string}>|null the engine list (conf/searchengines.php, filtered), loaded on first use */
    private $engines = null;

    public function reads() {

        return [ 'referer_host', 'referer_query', 'tagged_search_terms' ];
    }

    public function when() {

        /*
         * Selective in SQL, not in PHP. "Every referral with a query string"
         * would stream the partition through PHP, having moved the problem
         * rather than solved it.
         */
        return [
            [ 'tagged_search_terms', 'is null' ],
            [ 'referer_query', 'is not null' ],
            [ 'referer_host', 'is not null' ],
        ];
    }

    public function sessionReads() {

        return [ 'tagged_search_terms' ];
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
                [ '\OWA\Module\Base\Classes\TrackingEventHelpers', 'getSearchEngineList' ] );
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
