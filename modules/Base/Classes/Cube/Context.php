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
     * @param array $span
     * @param int   $built_at
     * @param int   $closed_before
     */
    function __construct( array $span, $built_at, $closed_before ) {

        $this->span          = $span;
        $this->built_at      = (int) $built_at;
        $this->closed_before = (int) $closed_before;
    }

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
