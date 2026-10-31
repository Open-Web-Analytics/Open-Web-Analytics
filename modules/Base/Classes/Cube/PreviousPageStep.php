<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * previous_page_path: the page viewed before this one in its session, or
 * (entrance) on the session's first page view.
 *
 * On page views only. Every other event leaves it NULL: "the page before" is a
 * question about a page view, and a report asks it with Page Views.
 *
 * Final as soon as the page view is built: an earlier page view cannot arrive
 * later and change it, except as a late beacon that a rebuild puts in place.
 */
class PreviousPageStep extends Step {

    /** What a session's first page view had before it. */
    const ENTRANCE = '(entrance)';

    public function requires() {

        return array( Context::PAGES );
    }

    public function execute( Context $context ) {

        return sprintf( "CASE WHEN %1\$s.event_type = 'page_view' THEN"
                      . " CASE WHEN %2\$s.has_prev = 1 THEN %2\$s.prev_path ELSE %3\$s END END",
            Context::RAW, Context::PAGES, $context->literal( self::ENTRANCE ) );
    }
}

?>
