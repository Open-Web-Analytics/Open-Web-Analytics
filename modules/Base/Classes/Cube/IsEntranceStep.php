<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * is_entrance: the session's first page view.
 *
 * The page view with the lowest position -- Context::pageViewKey(): sequence,
 * then arrival, then id -- so exactly one row per session, and only a page view.
 * A session_start or first_visit shares its carrier's position but is not a page
 * view, so it cannot take the entrance. A session with no page view has none.
 *
 * NOT GATED ON THE SESSION CLOSING, unlike is_exit: a session's first page view
 * is known when it arrives. A late beacon with an earlier sequence moves it, and
 * the next rebuild settles that.
 */
class IsEntranceStep extends Step {

    public function requires() {

        return array( Context::SESSION );
    }

    public function execute( Context $context ) {

        return sprintf( "CASE WHEN %1\$s.event_type = 'page_view' AND %2\$s = %3\$s.session_first_pv_key"
                      . ' THEN 1 ELSE 0 END',
            Context::RAW, Context::pageViewKey( Context::RAW ), Context::SESSION );
    }
}

?>
