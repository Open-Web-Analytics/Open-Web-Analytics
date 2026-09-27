<?php
namespace OWA\Module\Base\Classes\Cube;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * is_engaged_session: whether the session this event belongs to was engaged.
 *
 * A session is engaged when ANY of these holds:
 *
 *   - at least ENGAGED_MSEC of engagement time across its events;
 *   - at least ENGAGED_PAGE_VIEWS page views;
 *   - a goal event -- other than on a materialized event. A goal event
 *     triggered by session_start or first_visit marks a session that merely
 *     began, or belonged to a new visitor, and that is not engagement.
 *
 * Stamped on EVERY row of the session, so engagedSessions is a distinct count
 * of session_id under a one-column condition and isEngagedSession groups like
 * any other dimension.
 *
 * NOT TERMINAL. A session still in progress may be unengaged now and engaged
 * later; a rebuild REPLACES the partition, so the next one settles it. The
 * verdict only ever moves from 0 to 1 as a session grows.
 *
 * THE THRESHOLDS LIVE HERE AND ONLY HERE. Changing one is a rebuild, which is
 * the cost of the verdict being a column rather than a query-time aggregate.
 */
class IsEngagedSessionStep extends Step {

    /** Engagement time that makes a session engaged on its own: ten seconds. */
    const ENGAGED_MSEC = 10000;

    /** Page views that make a session engaged on their own. */
    const ENGAGED_PAGE_VIEWS = 2;

    public function requires() {

        return array( Context::SESSION );
    }

    public function execute( Context $context ) {

        return sprintf(
            'CASE WHEN %1$s.session_engagement_msec >= %2$d'
          . ' OR %1$s.session_page_views >= %3$d'
          . ' OR %1$s.session_has_goal = 1 THEN 1 ELSE 0 END',
            Context::SESSION, self::ENGAGED_MSEC, self::ENGAGED_PAGE_VIEWS );
    }
}

?>
