<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A registered custom dimension, read out of a JSON document at build time.
 *
 * An event-scoped one comes off `r.params`, which is on the row already and
 * costs no join. A user-scoped one comes off `v.properties`, which rides the
 * join the acq_* columns already make -- so the marginal cost is one
 * JSON_VALUE per joined visitor, proportional to distinct visitors in the
 * partition rather than to events, and nothing at all when nothing user-scoped
 * is registered, because the join is demand-driven.
 *
 * ORDINARY STEPS, DELIBERATELY. A registered dimension arrives at the assembler
 * as the same kind of thing every release column is, so the column list stays
 * the map and the joins stay demand-driven with no case for "and also the
 * custom ones".
 *
 * THE VALUE IS CLAMPED, NOT TRUSTED. A site may set a property of any length
 * and the column has a declared width; under STRICT_ALL_TABLES an over-long
 * value does not truncate, it aborts the whole INSERT ... SELECT and the
 * partition keeps its last good contents. One site's long string must not be
 * able to stop a Property's cube being built, so the clamp is in the
 * expression. A number that will not convert is NULL rather than an error --
 * measured, not assumed -- which is the same rule arriving from the server.
 */
class JsonStep extends Step {

    /** @var string the document to read: a qualified column */
    private $document;

    /** @var string JSON path, already known to need no quoting */
    private $path;

    /** @var string one of CustomDimension's types */
    private $type;

    /** @var int characters, for a string */
    private $max_length;

    /** @var string the join alias the document lives on, or '' for the raw row */
    private $join;

    /**
     * @param string $column     the cube column to fill
     * @param string $document   qualified JSON column, e.g. 'r.params'
     * @param string $path       JSON path, e.g. '$.plan'
     * @param string $type       string | integer | decimal
     * @param int    $max_length characters, when it is a string
     * @param string $join       Context alias to require, or '' for none
     */
    function __construct( $column, $document, $path, $type, $max_length = 0, $join = '' ) {

        parent::__construct( $column );

        $this->document   = (string) $document;
        $this->path       = (string) $path;
        $this->type       = (string) $type;
        $this->max_length = (int) $max_length;
        $this->join       = (string) $join;
    }

    public function requires() {

        return $this->join === '' ? array() : array( $this->join );
    }

    public function execute( Context $context ) {

        switch ( $this->type ) {

            case \OWA\Module\Base\Entity\CustomDimension::TYPE_INTEGER:
                return sprintf( OWA_SQL_JSON_VALUE_SIGNED, $this->document, $this->path );

            case \OWA\Module\Base\Entity\CustomDimension::TYPE_DECIMAL:
                return sprintf( OWA_SQL_JSON_VALUE_DOUBLE, $this->document, $this->path );
        }

        return sprintf( 'LEFT(%s, %d)',
            sprintf( OWA_SQL_JSON_VALUE, $this->document, $this->path ),
            $this->max_length > 0 ? $this->max_length : Dimensions::DIMENSION_LENGTH );
    }
}

?>
