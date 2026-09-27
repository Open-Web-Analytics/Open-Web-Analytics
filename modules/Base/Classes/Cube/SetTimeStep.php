<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * When a user property was set, stamped beside its value.
 *
 * So a row can say not only what the value is but whether it applied yet, and
 * an out-of-order drain cannot overwrite a newer value. It is NOT a dimension of its
 * own -- a microsecond value has one bucket per event, which is a pathological
 * thing to group by. What it is for is the test kind:
 *
 *     cd_plan_set_ts <= ts
 *
 * "the property was already in force at this event", which is boolean, and
 * which is the question a write-time stamp cannot otherwise answer. A cube row
 * carries the property's CURRENT value on every event of that visitor,
 * including events from before they set it; without this there is no way to
 * tell those apart.
 */
class SetTimeStep extends Step {

    /** @var string */
    private $document;

    /** @var string */
    private $path;

    /**
     * @param string $column
     * @param string $document qualified JSON column
     * @param string $path     JSON path to the timestamp
     */
    function __construct( $column, $document, $path ) {

        parent::__construct( $column );

        $this->document = (string) $document;
        $this->path     = (string) $path;
    }

    public function requires() {

        return array( Context::VISITOR );
    }

    public function execute( Context $context ) {

        return sprintf( OWA_SQL_JSON_VALUE_UNSIGNED, $this->document, $this->path );
    }
}

?>
