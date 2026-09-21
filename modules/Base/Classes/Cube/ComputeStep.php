<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A cube column whose value PHP has to work out, written set-based anyway.
 *
 * THE ESCAPE HATCH, AND WHY IT IS SHAPED LIKE THIS. What SQL can express was
 * deciding what the product could do: a reading that needed a real URL parse or
 * a percent-decode had nowhere to go but ingest, which is the one layer a
 * correction can never reach. That is backwards.
 *
 * So PHP may COMPUTE. What it may not do is STREAM. The invariant worth keeping
 * was never "no row passes through PHP" -- the classifier has always been PHP
 * shaping the statement -- it is the write shape: one bulk build, one swap, no
 * row-at-a-time writes, because that is what the atomicity and the columnar
 * door both rest on.
 *
 * HOW THAT IS ACHIEVED
 * Every compute step contributes its `reads` and its `when` to ONE shared
 * candidate query, run once per build however many are registered. Their rows
 * are offered to each step, the answers are bulk-inserted into one side table
 * with a column per step, and this step's SQL is a reference to that column.
 * The assembler joins the side table once. One read, one write, one swap.
 *
 * A step that issued its own query would make the build's cost grow with the
 * number of registered steps, which is the pipeline this design exists to
 * refuse. Hence no database handle on the Context.
 *
 * FILL, NEVER OVERWRITE. The emitted SQL is COALESCE(<the SQL answer>, <the
 * computed one>), so a computed value cannot contradict a tag or an
 * observation. It fills what SQL left NULL and nothing else.
 */
abstract class ComputeStep extends Step {

    /** @var array id => value, filled by compute() during execute() */
    private $values = array();

    /**
     * Raw columns this step's callback needs. Projected into the shared
     * candidate query; the step sees these and nothing else.
     *
     * @return string[]
     */
    abstract public function reads();

    /**
     * Which rows this step wants, as SHAPES rather than SQL -- each a
     * (column, operator) or (column, operator, value) triple. The assembler
     * renders them and ORs them with every other step's.
     *
     * SQL in a registration is what A.1.23 refuses for dimensions, for the
     * same reason: the first non-MySQL store would rewrite every one by hand.
     *
     * @return array[]
     */
    abstract public function when();

    /**
     * The expression filled where this step computed nothing -- usually the
     * SQL-only answer it is falling back from.
     *
     * @param Context $context
     * @return string
     */
    abstract public function fallback( Context $context );

    /**
     * Logical session values fallback() reads, so the window projects them.
     *
     * Declared rather than inferred: the factory cannot read an expression to
     * find out what it referenced.
     *
     * @return string[]
     */
    public function sessionReads() {

        return array();
    }

    /**
     * One row in, one value out. A PURE FUNCTION of its declared reads: no
     * database, no request, no clock. Two builds of one partition must agree,
     * or the swap's convergence guarantee is gone.
     *
     * @param array $row the columns named by reads()
     * @return string|null null where this row is not this step's
     */
    abstract public function compute( array $row );

    public function requires() {

        return array( Context::COMPUTED );
    }

    /**
     * Values this step computed, for the assembler to write into the side
     * table. Keyed by the row's primary key.
     *
     * @return array
     */
    public function values() {

        return $this->values;
    }

    /**
     * Run compute() over the candidates the shared query returned.
     *
     * @param Context $context
     * @return string
     */
    public function execute( Context $context ) {

        foreach ( $context->candidates as $row ) {

            $value = $this->compute( (array) $row );

            if ( $value !== null && $value !== '' ) {

                $this->values[ $row['id'] . ':' . $row['yyyymmdd'] ] = $value;
            }
        }

        return sprintf( 'COALESCE(%s, %s.%s)',
            $this->fallback( $context ), Context::COMPUTED, $this->column );
    }
}

?>
