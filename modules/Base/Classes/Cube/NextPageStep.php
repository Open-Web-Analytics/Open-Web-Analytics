<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * next_page_path: the page viewed after this one in its session, or (exit) on
 * the session's last page view once the session has closed.
 *
 * On page views only, like previous_page_path. The session's newest page view
 * stays NULL while the session is open -- the next page may still come -- and a
 * later rebuild settles it, the rule is_exit follows. "Closed" is read the same
 * way, off the session's newest arrival.
 */
class NextPageStep extends Step {

    /** What a closed session's last page view had after it. */
    const EXIT = '(exit)';

    public function requires() {

        return array( Context::SESSION, Context::PAGES );
    }

    public function execute( Context $context ) {

        return sprintf( "CASE WHEN %1\$s.event_type = 'page_view' THEN"
                      . " CASE WHEN %2\$s.has_next = 1 THEN %2\$s.next_path"
                      . " WHEN %3\$s.session_last_ts < %4\$d THEN %5\$s END END",
            Context::RAW, Context::PAGES, Context::SESSION, $context->closed_before,
            $context->literal( self::EXIT ) );
    }
}

?>
