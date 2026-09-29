<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * is_exit: the session's last page view, once the session has closed.
 *
 * The page view with the highest position -- Context::pageViewKey(): sequence,
 * then arrival, then id. A PAGE VIEW, not the last event of any kind: a click or
 * a scroll after it happened on the same page, and the exit is the page. A
 * session with no page view has none.
 *
 * The only terminal value in the cube. A session still inside the idle timeout
 * gets 0 on every row and a later rebuild settles it -- which works because a
 * build REPLACES the partition, so no session ever holds two exits. "Closed" is
 * read off the newest ARRIVAL, not the last position: whether a session has gone
 * quiet is a wall-clock question.
 */
class IsExitStep extends Step {

    public function requires() {

        return array( Context::SESSION );
    }

    public function execute( Context $context ) {

        return sprintf( "CASE WHEN %1\$s.event_type = 'page_view' AND %2\$s = %3\$s.session_last_pv_key"
                      . ' AND %3$s.session_last_ts < %4$d THEN 1 ELSE 0 END',
            Context::RAW, Context::pageViewKey( Context::RAW ), Context::SESSION, $context->closed_before );
    }
}

?>
