<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * source, and acq_source: the tag if there was one, else the referring host,
 * else direct.
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

    public function execute( Context $context ) {

        $resolved = sprintf( "COALESCE(NULLIF(TRIM(LOWER(%s)), ''), NULLIF(%s, ''), 'direct')",
            $this->tag, $this->host );

        if ( $this->absent === null ) {

            return $resolved;
        }

        return sprintf( 'CASE WHEN %s THEN %s ELSE %s END',
            $this->absent, $context->literal( \OWA\Module\Base\Classes\V2Event::UNRESOLVED ), $resolved );
    }
}

?>
