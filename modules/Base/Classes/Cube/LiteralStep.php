<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A cube column holding one value for the whole build.
 *
 * built_at is the only one: constant within a partition, which is what lets a
 * report say how fresh its answer is and what lets two queries notice a swap
 * landed between them.
 */
class LiteralStep extends Step {

    /** @var callable */
    private $value;

    /**
     * @param string   $column
     * @param callable $value  given the Context, returns the literal
     */
    function __construct( $column, $value ) {

        parent::__construct( $column );

        $this->value = $value;
    }

    public function execute( Context $context ) {

        return (string) call_user_func( $this->value, $context );
    }
}

?>
