<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * source, and acq_source: the tag if there was one, else the referring host,
 * else (direct) -- in parentheses because OWA generated it: a visit tagged
 * utm_source=direct stays `direct`.
 *
 * The host loses a leading www., so www.example.com and example.com are one
 * source. Nothing else is cut: m.facebook.com and en.wikipedia.org stay apart,
 * and a tagged source is used as tagged. referer_host in raw keeps the host as
 * it arrived.
 *
 * The reading, not the evidence. It lives in the cube rather than in raw
 * because the cube is rebuilt: a corrected classifier is re-applied by
 * rebuilding, where a value written at ingest would stay wrong.
 *
 * Where the visitor store has no row at all, acq_source gets the unresolved
 * sentinel rather than a guess -- the absence test is what distinguishes "no
 * row" from "a row with nothing in this column", which is an ordinary NULL.
 */
class SourceStep extends Step {

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
     * @param string      $tag    the tagged value, qualified
     * @param string      $host   the referring host, qualified
     * @param string      $join   which join they live on
     * @param string|null $absent SQL test that is true when there is no row
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

    /**
     * The source of a tagged value and a referring host, as SQL. Realtime reads
     * the visitor store with it too, so the two never name a source differently.
     *
     * @param  string $tag  the tagged value, qualified
     * @param  string $host the referring host, qualified
     * @return string
     */
    public static function resolve( $tag, $host ) {

        return sprintf( "COALESCE(NULLIF(TRIM(LOWER(%s)), ''), NULLIF(%s, ''), '(direct)')",
            $tag, self::hostSource( $host ) );
    }

    /** A referring host as a source: lower case, without a leading www. */
    private static function hostSource( $host ) {

        $lower = sprintf( 'LOWER(%s)', $host );

        return sprintf( "CASE WHEN %1\$s LIKE 'www.%%' THEN SUBSTRING(%1\$s, 5) ELSE %1\$s END", $lower );
    }

    public function execute( Context $context ) {

        $resolved = self::resolve( $this->tag, $this->host );

        if ( $this->absent === null ) {

            return $resolved;
        }

        return sprintf( 'CASE WHEN %s THEN %s ELSE %s END',
            $this->absent, $context->literal( \OWA\Module\Base\Classes\V2Event::UNRESOLVED ), $resolved );
    }
}

?>
