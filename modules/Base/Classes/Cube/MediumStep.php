<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * medium, and acq_medium: the tag if there was one, else the referrer
 * classified as organic search, a social network, a plain referral, or direct.
 *
 * THE CLASSIFICATION IS IN THE CUBE, NOT AT INGEST, and that is the whole
 * reason this step exists rather than a column. The list grows and gets
 * corrected -- duckduckgo was missing from it until January 2023, so every OWA
 * install recorded those arrivals as `referral` and v1 can never fix that
 * history. Here the same correction plus a rebuild fixes every affected row.
 *
 * The list is read in PHP and compiled into the statement, which is the
 * existing proof that PHP already shapes this SQL -- what it does not do is
 * stream rows.
 */
class MediumStep extends Step {

    /** @var string */
    private $tag;

    /** @var string */
    private $host;

    /** @var string */
    private $join;

    /** @var string|null */
    private $absent;

    /**
     * @param string      $column
     * @param string      $tag
     * @param string      $host
     * @param string      $join
     * @param string|null $absent
     */
    function __construct( $column, $tag, $host, $join, $absent = null ) {

        parent::__construct( $column );

        $this->tag    = (string) $tag;
        $this->host   = (string) $host;
        $this->join   = (string) $join;
        $this->absent = $absent;
    }

    public function requires() {

        return array( $this->join );
    }

    public function execute( Context $context ) {

        $resolved = sprintf( "COALESCE(NULLIF(TRIM(LOWER(%s)), ''), CASE %s ELSE 'referral' END)",
            $this->tag, implode( ' ', $this->branches( $context ) ) );

        if ( $this->absent === null ) {

            return $resolved;
        }

        return sprintf( 'CASE WHEN %s THEN %s ELSE %s END',
            $this->absent, $context->literal( \OWA\Module\Base\Classes\V2Event::UNRESOLVED ), $resolved );
    }

    /**
     * @param Context $context
     * @return string[]
     */
    private function branches( Context $context ) {

        $helpers = '\OWA\Module\Base\Classes\TrackingEventHelpers';

        $branches = array(
            sprintf( "WHEN %s IS NULL OR %s = '' THEN 'direct'", $this->host, $this->host ),
        );

        foreach ( array(
            'organic-search' => call_user_func( array( $helpers, 'getSearchEngineList' ) ),
            'social-network' => call_user_func( array( $helpers, 'getSocialNetworkList' ) ),
        ) as $medium => $list ) {

            $tests = array();

            foreach ( (array) $list as $entry ) {

                if ( empty( $entry['domain'] ) ) {

                    continue;
                }

                // Substring containment, as isSearchEngine() matches: the list
                // holds 'google', not a hostname. OWA_SQL_CONTAINS because
                // LOCATE is MySQL's spelling of it and this step must not know.
                $tests[ $entry['domain'] ] = sprintf( OWA_SQL_CONTAINS,
                    $context->literal( $entry['domain'] ), $this->host );
            }

            if ( $tests ) {

                $branches[] = sprintf( "WHEN %s THEN '%s'", implode( ' OR ', $tests ), $medium );
            }
        }

        return $branches;
    }
}

?>
