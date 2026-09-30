<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * medium, and acq_medium: the tag if there was one, else the medium of an
 * untagged visit.
 *
 *   no referrer                           (none)
 *   an AI assistant (conf/aiassistants)   ai-agent
 *   a search engine (conf/searchengines)  organic
 *   any other site, social ones included  referral
 *
 * A MEDIUM, NOT A CHANNEL. Social, paid and the rest are the channel's to say
 * (ChannelStep), from the source and the medium together; a Facebook visit is
 * medium `referral` and channel Organic Social. This used to put
 * the classification here -- `organic-search`, `social-network` -- so the
 * column mixed what a site tagged with what OWA decided.
 *
 * THE CLASSIFICATION IS IN THE CUBE, NOT AT INGEST, and that is the whole
 * reason this step exists rather than a column. The lists grow and get
 * corrected -- duckduckgo was missing from the search list until January 2023,
 * so every OWA install recorded those arrivals as `referral` and v1 can never
 * fix that history. Here the same correction plus a rebuild fixes every
 * affected row.
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

        $resolved = sprintf( 'COALESCE(NULLIF(TRIM(LOWER(%s)), \'\'), %s)',
            $this->tag, $this->untagged( $context ) );

        if ( $this->absent === null ) {

            return $resolved;
        }

        return sprintf( 'CASE WHEN %s THEN %s ELSE %s END',
            $this->absent, $context->literal( \OWA\Module\Base\Classes\V2Event::UNRESOLVED ), $resolved );
    }

    /**
     * The medium of an untagged visit, from its referring host alone.
     *
     * CampaignStep mirrors it for an untagged visit's campaign, so the two
     * are one expression and cannot disagree.
     */
    public function untagged( Context $context ) {

        $host = sprintf( 'LOWER(%s)', $this->host );

        // AI assistants first: gemini.google.com is an assistant, and on the
        // search list's `google` it would read as organic.
        return sprintf( "CASE WHEN %1\$s IS NULL OR %1\$s = '' THEN '(none)'"
          . " WHEN %2\$s THEN 'ai-agent' WHEN %3\$s THEN 'organic' ELSE 'referral' END",
            $this->host,
            SiteLists::matches( $host, 'ai', $context ),
            SiteLists::matches( $host, 'search', $context ) );
    }
}

?>
