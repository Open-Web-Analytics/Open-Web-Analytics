<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * is_exit: the session's last event, once the session has closed.
 *
 * The only terminal value in the cube. A session still inside the idle timeout
 * gets 0 on every row and a later rebuild settles it -- which works because a
 * build REPLACES the partition, so no session ever holds two exits.
 */
class IsExitStep extends Step {

    public function requires() {

        return array( Context::SESSION );
    }

    public function execute( Context $context ) {

        return sprintf( 'CASE WHEN %s.id = %s.session_last_id AND %s.session_last_ts < %d THEN 1 ELSE 0 END',
            Context::RAW, Context::SESSION, Context::SESSION, $context->closed_before );
    }
}

?>
