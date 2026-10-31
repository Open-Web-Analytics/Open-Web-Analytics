<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * page_depth: which page of its session this is -- 1 for the landing page.
 *
 * Every event carries the depth of the page it happened on, so a click on the
 * third page is at depth 3. An event before the session's first page view (a
 * session_start materialized beside it) has none.
 *
 * Counted in device order over the same window as is_entrance and is_exit, so
 * depth 1 is the entrance.
 */
class PageDepthStep extends Step {

    public function requires() {

        return array( Context::SESSION );
    }

    public function execute( Context $context ) {

        return sprintf( 'NULLIF(%s.session_page_depth, 0)', Context::SESSION );
    }
}

?>
