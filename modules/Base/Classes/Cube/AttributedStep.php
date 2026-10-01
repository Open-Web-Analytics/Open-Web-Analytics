<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * attributed_source, attributed_medium, attributed_campaign: the last
 * non-direct touch within the Property's lookback (PLAN 2.29).
 *
 *   the session arrived with tags or a referrer   its own reading
 *   else the visitor's last non-direct touch      that touch's reading,
 *     is within the lookback                      by the same step
 *   else                                          its own reading: (direct)
 *
 * TWO ORDINARY STEPS, ONE CHOICE. Each side is read by the step that reads it
 * everywhere else -- a SourceStep over the session's tags and referrer, and
 * another over the touch stamped on its first event -- so the attributed value
 * of a session that arrived non-direct is exactly its `source`, and the one it
 * inherits is exactly what that earlier session's `source` was.
 *
 * THE WINDOW IS APPLIED HERE, not at ingest: prior_touch_ts against the
 * session's first event, by the lookback on the Context. The stamp is the
 * visitor's MOST RECENT touch, so a touch outside the window means every
 * earlier one is too, and (direct) is right however the window changes -- which
 * is what makes a changed lookback rebuildable in both directions.
 */
class AttributedStep extends Step {

    /** @var Step reads the session's own evidence */
    private $own;

    /** @var Step reads the prior touch */
    private $prior;

    /** @var string[] the session's own tags and referring host, qualified */
    private $own_evidence;

    /** @var string[] the prior touch's tags and referring host, qualified */
    private $prior_evidence;

    /** @var string */
    private $prior_ts;

    /** @var string */
    private $session_ts;

    /**
     * @param string   $column
     * @param Step     $own
     * @param Step     $prior
     * @param string[] $own_evidence
     * @param string[] $prior_evidence
     * @param string   $prior_ts   when the prior touch was, microseconds
     * @param string   $session_ts when the session began, microseconds
     */
    function __construct( $column, Step $own, Step $prior, array $own_evidence, array $prior_evidence,
                          $prior_ts, $session_ts ) {

        parent::__construct( $column );

        $this->own            = $own;
        $this->prior          = $prior;
        $this->own_evidence   = array_map( 'strval', $own_evidence );
        $this->prior_evidence = array_map( 'strval', $prior_evidence );
        $this->prior_ts       = (string) $prior_ts;
        $this->session_ts     = (string) $session_ts;
    }

    public function requires() {

        return array_values( array_unique( array_merge( $this->own->requires(), $this->prior->requires() ) ) );
    }

    public function execute( Context $context ) {

        return sprintf( 'CASE WHEN %s THEN %s WHEN %s AND %s THEN %s ELSE %s END',
            self::anyPresent( $this->own_evidence ),
            $this->own->execute( $context ),
            self::anyPresent( $this->prior_evidence ),
            $this->withinWindow( $context ),
            $this->prior->execute( $context ),
            $this->own->execute( $context ) );
    }

    /** True when the prior touch is no older than the lookback. */
    private function withinWindow( Context $context ) {

        return sprintf( '(%1$s IS NOT NULL AND %1$s <= %2$s AND %1$s >= %2$s - %3$d)',
            $this->prior_ts, $this->session_ts, $context->lookback_usec );
    }

    /**
     * A touch is non-direct when any of its tags, or its referring host, holds
     * something.
     *
     * @param string[] $columns
     * @return string
     */
    private static function anyPresent( array $columns ) {

        $tests = array();

        foreach ( $columns as $column ) {

            $tests[] = sprintf( "NULLIF(TRIM(%s), '') IS NOT NULL", $column );
        }

        return '(' . implode( ' OR ', $tests ) . ')';
    }
}

?>
