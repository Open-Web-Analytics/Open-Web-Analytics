<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * What a cube step is given when it runs: the partition, the clocks, and the
 * aliases it may write SQL against.
 *
 * A step never reaches past this for anything. It has no database handle by
 * design -- a step that queried would make the build's cost depend on how many
 * steps are registered, which is the shape the design refuses. The only rows
 * PHP ever sees are the ones the shared candidate pass already fetched, and
 * they arrive here.
 */
class Context {

    /** Alias of the raw table in the build. */
    const RAW = 'r';

    /** Alias of the windowed session subquery. */
    const SESSION = 's';

    /** Alias of the visitor store. */
    const VISITOR = 'v';

    /** Alias of the side table the compute steps write. */
    const COMPUTED = 'c';

    /** @var array the partition being built: name, start, less_than */
    public $span;

    /** @var int microseconds stamped on every row of this build */
    public $built_at;

    /** @var int a session whose last event is older than this has closed */
    public $closed_before;

    /** @var array rows the candidate pass returned, keyed by nothing in particular */
    public $candidates = array();

    /**
     * How far back a direct session may take the visitor's last non-direct
     * touch, in microseconds: the Property's attribution_lookback_days
     * (AttributedStep, PLAN 2.29).
     *
     * @var int
     */
    public $lookback_usec;

    /**
     * A page view's position in its session, as one string that sorts in order.
     *
     * Sequence first -- counted on the device, so a late beacon cannot reorder
     * it -- with a missing one as 0, so a session spanning a tracker upgrade puts
     * its unsequenced page views first, where they happened. Then arrival time,
     * which orders a session with no sequence at all. Then the id, so exactly one
     * row holds the minimum and one the maximum, even where two tabs stamped the
     * same sequence.
     *
     * Zero-padded to widths that hold each part: a sequence under ten digits, a
     * microsecond timestamp, and a 63-bit id (V2Event::id() drops the sign bit).
     *
     * @param string $alias the table alias
     * @return string SQL
     */
    public static function pageViewKey( $alias ) {

        return sprintf(
            "CONCAT(LPAD(COALESCE(%1\$s.event_seq, 0), 10, '0'), LPAD(%1\$s.ts, 20, '0'), LPAD(%1\$s.id, 20, '0'))",
            $alias );
    }

    /**
     * @param array $span
     * @param int   $built_at
     * @param int   $closed_before
     * @param int   $lookback_days
     */
    function __construct( array $span, $built_at, $closed_before, $lookback_days = self::DEFAULT_LOOKBACK_DAYS ) {

        $this->span          = $span;
        $this->built_at      = (int) $built_at;
        $this->closed_before = (int) $closed_before;
        $this->lookback_usec = max( 0, (int) $lookback_days ) * 86400 * 1000000;
    }

    /** attribution_lookback_days where nothing says otherwise. */
    const DEFAULT_LOOKBACK_DAYS = 90;

    /**
     * A quoted, escaped SQL string literal.
     *
     * On the context rather than on each step because escaping depends on the
     * connection's character set, so it has to go through the driver.
     *
     * @param string $value
     * @return string
     */
    public function literal( $value ) {

        return "'" . \OWA\Core\CoreAPI::dbSingleton()->prepare( $value ) . "'";
    }

    /**
     * A stored text value, or NULL where there is nothing in it.
     *
     * '' and whitespace both mean absent, and absence is NULL.
     *
     * @param string $column
     * @return string
     */
    public function text( $column ) {

        return sprintf( "NULLIF(TRIM(%s), '')", $column );
    }
}

?>
