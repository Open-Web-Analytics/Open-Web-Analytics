<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * One derived column of the cube, and how a build fills it.
 *
 * The cube's column list is the map: every column past raw's has
 * exactly one step, and the assembler composes their output into one statement.
 * Registering none for a column is a fatal, and so is registering two -- which
 * is the point of keying on the column rather than appending to a list. The
 * previous shape built the SELECT list by appending sixteen expressions in an
 * order that had to match the entity's, and a reordered entity would have put
 * the right values in the wrong columns with no error anywhere.
 *
 * A STEP EMITS SQL. That is the contract, and it holds for both kinds: a step
 * that only needs SQL returns an expression, and a step that needs PHP returns
 * an expression over the side table it has just filled. The assembler never
 * learns which is which, so a column needing real computation costs the builder no
 * special case -- see ComputeStep.
 *
 * WHAT A STEP MAY NOT DO
 *   - read another step's output. A build is one flat pass over the rows; a step wanting a
 *     derived value is asking for a second one, and should be refused rather
 *     than turned into a dependency graph.
 *   - query. It gets a Context, not a database handle.
 *   - spell anything platform-specific itself. Non-ANSI syntax comes from the
 *     dialect's OWA_SQL_* constants, as the classifier already does, or the
 *     first non-MySQL store rewrites every step by hand.
 */
abstract class Step {

    /** @var string the owa_event column this step fills */
    protected $column;

    /**
     * @param string $column
     */
    function __construct( $column ) {

        $this->column = (string) $column;
    }

    /** @return string */
    public function column() {

        return $this->column;
    }

    /**
     * A name for accounting and error messages.
     *
     * @return string
     */
    public function name() {

        $parts = explode( '\\', get_class( $this ) );

        return end( $parts ) . '(' . $this->column . ')';
    }

    /**
     * Which joins the assembler has to emit for this step's SQL to resolve.
     *
     * Demand-driven: a join nothing asks for is not in the statement. Values
     * are Context::SESSION, Context::VISITOR and Context::COMPUTED.
     *
     * @return string[]
     */
    public function requires() {

        return array();
    }

    /**
     * Do the work, and return the SQL expression for this column.
     *
     * Called once per run, after the candidate pass and before the build. A
     * pure-SQL step does nothing here but return its expression.
     *
     * @param Context $context
     * @return string
     * @throws \RuntimeException where the step cannot produce its column
     */
    abstract public function execute( Context $context );
}

?>
