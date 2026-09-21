<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * A cube column copied straight from a joined row.
 *
 * The landing page set and the tagged values that need no reading: a value
 * resolved on one row of a session, stamped onto every row of it.
 *
 * `text` decides whether '' and whitespace collapse to NULL on the way. The
 * landing page columns do NOT -- they are copies, and a copy that edits its
 * source can disagree with the row it came from.
 */
class CopyStep extends Step {

    /** @var string */
    private $source;

    /** @var string */
    private $join;

    /** @var bool */
    private $text;

    /** @var string|null */
    private $absent;

    /**
     * @param string $column the cube column to fill
     * @param string $source qualified column on a joined alias
     * @param string $join   which join the source lives on
     * @param bool        $text   collapse empty to NULL
     * @param string|null $absent SQL test that is true when there is no row
     */
    function __construct( $column, $source, $join, $text = false, $absent = null ) {

        parent::__construct( $column );

        $this->source = (string) $source;
        $this->join   = (string) $join;
        $this->text   = (bool) $text;
        $this->absent = $absent;
    }

    public function requires() {

        return array( $this->join );
    }

    public function execute( Context $context ) {

        $value = $this->text ? $context->text( $this->source ) : $this->source;

        if ( $this->absent === null ) {

            return $value;
        }

        // No row in the store at all is "unresolved", which is a different
        // statement from a row holding NULL in this one column.
        return sprintf( 'CASE WHEN %s THEN %s ELSE %s END',
            $this->absent,
            $context->literal( \OWA\Module\Base\Classes\V2Event::UNRESOLVED ),
            $value );
    }
}

?>
