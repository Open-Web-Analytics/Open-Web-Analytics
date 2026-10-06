<?php

/**
 * The metrics that read a Property's reporting cube.
 *
 * A definition names a column and a KIND the query builder already knows how to
 * render, so it carries no SQL -- which is what makes it a file rather than a
 * class. Core\Module::registerMetricsFromConfig() picks this up.
 *
 * `eventCount` is every row, which is what the cube makes cheap and what 1.x
 * could not express: its `actions` counted one fact table.
 *
 * A NAME REACHES THE CUBE ONLY THROUGH A METRIC. ResultSetManager reduces the
 * candidate entities to those that can compute every requested metric, so until
 * a metric here names the cube, no query can select it however many dimensions
 * it carries.
 */
return array(

    'entity' => 'base.event',

    'metrics' => array(

        // ---- every row --------------------------------------------------
        'eventCount' => array(
            'label'       => 'Events',
            'description' => 'The total number of events.',
            'group'       => 'Site Usage',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
        ),

        /*
         * ---- counting SOME rows ----------------------------------------
         *
         * A `condition` restricts what is counted to the rows matching one
         * test, and it is the same scan: `sum(CASE WHEN ... THEN 1 ELSE 0
         * END)`, no subquery. Most of this vocabulary is "count the rows that
         * are X", which is what an event table makes cheap and what 1.x could
         * not express -- its `actions` counted one fact table.
         */
        'pageViews' => array(
            'label'       => 'Page Views',
            'description' => 'The total number of pages viewed.',
            'group'       => 'Site Usage',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => array( 'column' => 'event_type', 'value' => 'page_view' ),
        ),

        /*
         * Exits: the last event of a session, counted on the page it happened
         * on. A METRIC, not a dimension, which is the whole point of it.
         *
         * 1.x spelled this as exitPagePath / exitPageUrl / exitPageTitle --
         * dimensions that resolve to a page only on the session's last row.
         * Grouping by one puts every OTHER view of that page into a single
         * anonymous bucket, so the report loses the denominator and cannot
         * state an exit rate at all -- the only number anyone wants exits for.
         * Measured on three sessions (/a then /b, /a then /c, /a alone) the
         * dimension reports /a once; /a was viewed three times and exited from
         * once.
         *
         * So Exits is a metric, paired with the ordinary page dimension, and
         * there is no exit-page dimension at all. Entrances likewise.
         *
         * THE SESSION'S LAST PAGE VIEW, by device sequence (Cube\IsExitStep):
         * not the last event of any kind, since a click after it happened on
         * the same page, and not by arrival, since a late beacon would move it.
         */
        'exits' => array(
            'label'       => 'Exits',
            'description' => 'The number of sessions that ended on a page view. Grouped by page, the number that ended on each.',
            'group'       => 'Site Usage',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => array( 'column' => 'is_exit', 'value' => 1 ),
        ),

        /*
         * Of the times a page was viewed, the share that were a session's last.
         * Grouped by page it is each page's exit rate; ungrouped, exits over all
         * page views.
         */
        'exitRate' => array(
            'label'       => 'Exit Rate',
            'description' => 'The share of page views that were the last in their session.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'percentage',
            'numerator'   => 'exits',
            'denominator' => 'pageViews',
            'precision'   => 4,
        ),

        /* The session's first page view, the mirror of exits (Cube\IsEntranceStep). */
        'entrances' => array(
            'label'       => 'Entrances',
            'description' => 'The number of sessions that began on a page view. Grouped by page, the number that began on each.',
            'group'       => 'Site Usage',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => array( 'column' => 'is_entrance', 'value' => 1 ),
        ),

        'goalConversions' => array(
            'label'       => 'Goal Conversions',
            'description' => 'The number of events that met a goal condition.',
            'group'       => 'Goals',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => array( 'column' => 'is_goal_event', 'value' => 1 ),
        ),

        /*
         * ---- commerce ----------------------------------------------------
         *
         * All of it off two columns on the event row -- `revenue` in minor
         * units and the `currency` beside it -- and the purchase event type.
         * 1.x needed two fact tables and a line-item join for the same set.
         *
         * MINOR UNITS, so these are integers and the formatter renders them.
         * Summing minor units of different currencies is meaningless, which is
         * why currency is a dimension: a multi-currency store groups by it.
         */
        'transactions' => array(
            'label'       => 'Transactions',
            'description' => 'The number of completed purchases.',
            'group'       => 'Ecommerce',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => array( 'column' => 'event_type', 'value' => 'purchase' ),
        ),

        'taxRevenue' => array(
            'label'       => 'Tax',
            'description' => 'Total tax collected on completed purchases.',
            'group'       => 'Ecommerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'column'      => 'tax',
            'condition'   => array( 'column' => 'event_type', 'value' => 'purchase' ),
        ),

        'shippingRevenue' => array(
            'label'       => 'Shipping',
            'description' => 'Total shipping charged on completed purchases.',
            'group'       => 'Ecommerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'column'      => 'shipping',
            'condition'   => array( 'column' => 'event_type', 'value' => 'purchase' ),
        ),

        'transactionRevenue' => array(
            'label'       => 'Revenue',
            'description' => 'Total revenue from completed purchases.',
            'group'       => 'Ecommerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'column'      => 'revenue',
            'condition'   => array( 'column' => 'event_type', 'value' => 'purchase' ),
        ),

        // ---- counting distinct things -----------------------------------
        /*
         * `session_id` ALONE, not paired with the visitor.
         *
         * Util.generateRandomGuid() gives it 62 random bits: the expected
         * collisions among n session ids are n^2 / 2^63, a few hundredths over
         * a decade of a large site. Ids from before 2.0 were a unix timestamp
         * plus nine random digits -- about 30 random bits per second, roughly
         * 0.8 collisions a year at 10 new sessions a second -- and history
         * keeps them. Measured across 15,643 sessions of real history: zero
         * session ids shared by more than one visitor.
         *
         * Pairing is also actively worse here. COUNT(DISTINCT a, b) drops any
         * row where either column is NULL, and 48 of those sessions carry a
         * NULL visitor id -- so the pair under-counts by exactly 48 to guard
         * against a one-in-a-billion collision.
         *
         * Pairing is only necessary where a session id is a bare start timestamp
         * with no randomness in it. Ours is not.
         */
        'sessions' => array(
            'label'       => 'Sessions',
            'description' => 'The number of sessions.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'session_id',
        ),

        'totalUsers' => array(
            'label'       => 'Total Users',
            'description' => 'The number of distinct users.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'visitor_id',
        ),

        'newUsers' => array(
            'label'       => 'New Users',
            'description' => 'Users whose first session this is.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'visitor_id',
            'condition'   => array( 'column' => 'prior_sessions', 'value' => 0 ),
        ),

        /*
         * Users who scrolled. A page view sends a scroll event only after the
         * visitor has scrolled past the first threshold, so any scroll event
         * counts; group by scrollDepth for how far they got.
         */
        'scrolledUsers' => array(
            'label'       => 'Scrolled Users',
            'description' => 'The number of distinct users who scrolled a page.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'visitor_id',
            'condition'   => array( 'column' => 'event_type', 'value' => 'scroll' ),
        ),

        /*
         * newVisitors has no `returningVisitors` twin, deliberately.
         *
         * To count returning users you take totalUsers and group it by the
         * New/returning dimension. Two metrics splitting
         * one population is the same shape as the isNewVisitor /
         * isRepeatVisitor dimensions this replaced -- and newVsReturning is
         * exactly the dimension to group uniqueVisitors by.
         */

        // ---- summing ------------------------------------------------------
        /*
         * ---- ratios --------------------------------------------------------
         *
         * `ratio` rather than a formula: every calculated metric in 1.x is a
         * division, and a formula string costs an eval, a substitution by
         * metric name that collides when one name contains another, and a
         * child list restating what the formula already names. A ratio names
         * its two sides, so the children are derived and nothing is
         * substituted.
         *
         * A zero denominator gives NULL, not 0 -- "no visits, so pages per
         * visit is not a number" is a different answer from "pages per visit is
         * zero", and the formatter renders the first as absent.
         */
        'pageViewsPerSession' => array(
            'label'       => 'Pages Per Session',
            'description' => 'The average number of pages viewed per session.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'pageViews',
            'denominator' => 'sessions',
            'precision'   => 2,
        ),

        /*
         * ---- refunds -------------------------------------------------------
         *
         * A refund is its own event, the amount refunded in `revenue` as a
         * positive number. transactionRevenue stays what was sold; netRevenue
         * is that less what was refunded.
         */
        'refunds' => array(
            'label'       => 'Refunds',
            'description' => 'The number of refunds.',
            'group'       => 'Ecommerce',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => array( 'column' => 'event_type', 'value' => 'refund' ),
        ),

        'refundAmount' => array(
            'label'       => 'Refund Amount',
            'description' => 'The amount refunded, excluding tax and shipping.',
            'group'       => 'Ecommerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'column'      => 'revenue',
            'condition'   => array( 'column' => 'event_type', 'value' => 'refund' ),
        ),

        'netRevenue' => array(
            'label'       => 'Net Revenue',
            'description' => 'Revenue less refunds, excluding tax and shipping.',
            'group'       => 'Ecommerce',
            'metric_type' => 'difference',
            'data_type'   => 'currency',
            'minuend'     => 'transactionRevenue',
            'subtrahend'  => 'refundAmount',
        ),

        'revenuePerTransaction' => array(
            'label'       => 'Average Order Value',
            'description' => 'Revenue divided by the number of purchases.',
            'group'       => 'Ecommerce',
            'metric_type' => 'ratio',
            'data_type'   => 'currency',
            'numerator'   => 'transactionRevenue',
            'denominator' => 'transactions',
            'precision'   => 0,
        ),

        'revenuePerSession' => array(
            'label'       => 'Revenue Per Session',
            'description' => 'Revenue divided by the number of sessions.',
            'group'       => 'Ecommerce',
            'metric_type' => 'ratio',
            'data_type'   => 'currency',
            'numerator'   => 'transactionRevenue',
            'denominator' => 'sessions',
            'precision'   => 0,
        ),

        /*
         * Sessions with at least one purchase. A session that bought twice is
         * one purchasing session and two transactions, and the conversion rate
         * is about sessions.
         */
        'purchasingSessions' => array(
            'label'       => 'Purchasing Sessions',
            'description' => 'The number of sessions that included a purchase.',
            'group'       => 'Ecommerce',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'session_id',
            'condition'   => array( 'column' => 'event_type', 'value' => 'purchase' ),
        ),

        /*
         * PURCHASING SESSIONS over sessions. It was transactions over sessions,
         * which is transactions per session under a name promising a share: two
         * purchases in one of two sessions read 100% where the share is 50%, and
         * a store with repeat buyers could pass 100%.
         */
        'ecommerceConversionRate' => array(
            'label'       => 'Ecommerce Conversion Rate',
            'description' => 'The share of sessions that completed a purchase.',
            'group'       => 'Ecommerce',
            'metric_type' => 'ratio',
            'data_type'   => 'percentage',
            'numerator'   => 'purchasingSessions',
            'denominator' => 'sessions',
            'precision'   => 4,
        ),

        /*
         * Goal conversions, as a rate and a share, off the flag the event row
         * already carries. 1.x spelled these goalConversionRateAll /
         * goalValueAll and needed the goal configuration to do it.
         */
        'goalConversionRatePerSession' => array(
            'label'       => 'Goal Conversion Rate Per Session',
            'description' => 'The share of sessions that included a goal conversion.',
            'group'       => 'Goals',
            'metric_type' => 'ratio',
            'data_type'   => 'percentage',
            'numerator'   => 'goalConversions',
            'denominator' => 'sessions',
            'precision'   => 4,
        ),

        /*
         * ---- the per-USER ratios -----------------------------------------
         *
         * Every one is arithmetic over metrics that already exist, so they cost
         * a declaration each.
         *
         * A per-USER denominator answers a different question from a
         * per-session one -- "how much does a person do" against "how much
         * happens in a visit" -- which is why both exist and why naming the
         * denominator in the metric is not pedantry.
         */
        'eventCountPerUser' => array(
            'label'       => 'Events Per User',
            'description' => 'The average number of events per user.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'eventCount',
            'denominator' => 'totalUsers',
            'precision'   => 2,
        ),

        'pageViewsPerUser' => array(
            'label'       => 'Page Views Per User',
            'description' => 'The average number of pages viewed per user.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'pageViews',
            'denominator' => 'totalUsers',
            'precision'   => 2,
        ),

        'averageEngagementTimePerUser' => array(
            'label'       => 'Average Engagement Time Per User',
            'description' => 'Average time accrued per user, in milliseconds.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'milliseconds',
            'numerator'   => 'totalEngagementTime',
            'denominator' => 'totalUsers',
            'precision'   => 0,
        ),

        'revenuePerUser' => array(
            'label'       => 'Revenue Per User',
            'description' => 'Revenue divided by the number of users.',
            'group'       => 'Ecommerce',
            'metric_type' => 'ratio',
            'data_type'   => 'currency',
            'numerator'   => 'transactionRevenue',
            'denominator' => 'totalUsers',
            'precision'   => 0,
        ),

        'goalConversionRatePerUser' => array(
            'label'       => 'Goal Conversion Rate Per User',
            'description' => 'The share of users who triggered a goal conversion.',
            'group'       => 'Goals',
            'metric_type' => 'ratio',
            'data_type'   => 'percentage',
            'numerator'   => 'goalConversions',
            'denominator' => 'totalUsers',
            'precision'   => 4,
        ),

        'sessionsPerUser' => array(
            'label'       => 'Sessions Per User',
            'description' => 'The average number of sessions per user.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'sessions',
            'denominator' => 'totalUsers',
            'precision'   => 2,
        ),

        'eventsPerSession' => array(
            'label'       => 'Events Per Session',
            'description' => 'The average number of events recorded per session.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'eventCount',
            'denominator' => 'sessions',
            'precision'   => 2,
        ),

        /*
         * A NAME SAYS WHICH IT IS. This is a total and the one below is an
         * average, and the pair is unreadable if either is called plain
         * "Engagement Time" -- which is the defect 1.x's `visitDuration` had:
         * an AVG whose name promised a duration, so every report showing it
         * was showing an average nobody had been told about.
         */
        'totalEngagementTime' => array(
            'label'       => 'Total Engagement Time',
            'description' => 'Time accrued on pages, in milliseconds. Includes final-page dwell, which 1.x cannot measure.',
            'group'       => 'Site Usage',
            'metric_type' => 'sum',
            'data_type'   => 'milliseconds',
            'column'      => 'engagement_msec',
        ),

        /*
         * REPLACES 1.x's visitDuration, and measures something it could not.
         *
         * visitDuration was AVG(session.last_req - session.timestamp): the span
         * between a session's first and last BEACON. So it could never see the
         * final page -- whatever the visitor did after the last beacon was
         * invisible -- and a single-pageview visit scored exactly 0 however long
         * they read it. The entire bounce population contributed zero to the
         * site's average.
         *
         * Engagement time is accrued on the DEVICE and sent, so the last page
         * counts.
         *
         * No ordering caveat, unlike exits: this sums over a session, so which
         * event the pass calls last does not enter into it.
         */
        /*
         * PER SESSION, and the name says so. There is a per-user one too, and a
         * bare "average engagement time" does not say which denominator it used --
         * the same defect as 1.x's visitDuration, which was an AVG under a name
         * that promised a duration.
         */
        'averageEngagementTimePerSession' => array(
            'label'       => 'Average Engagement Time Per Session',
            'description' => 'Average time accrued per session, in milliseconds.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'milliseconds',
            'numerator'   => 'totalEngagementTime',
            'denominator' => 'sessions',
            'precision'   => 0,
        ),

        /*
         * Engagement time per page view. Grouped by pagePath it is the time
         * spent on each page: every event carries its page, so a page's
         * engagement is the sum over the rows that name it.
         */
        'averageEngagementTimePerPageView' => array(
            'label'       => 'Average Engagement Time Per Page View',
            'description' => 'Average time accrued per page view, in milliseconds.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'milliseconds',
            'numerator'   => 'totalEngagementTime',
            'denominator' => 'pageViews',
            'precision'   => 0,
        ),

        /*
         * ---- engaged sessions ---------------------------------------------
         *
         * Off is_engaged_session, the cube's verdict on the whole session: ten
         * seconds of engagement, two page views, or a goal event other than on
         * a materialized event. The rule is Classes\Cube\IsEngagedSessionStep.
         *
         * A distinct count under a one-column condition, because the flag is on
         * every row of the session.
         */
        'engagedSessions' => array(
            'label'       => 'Engaged Sessions',
            'description' => 'Sessions that lasted ten seconds or more, had two or more page views, or met a goal.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'session_id',
            'condition'   => array( 'column' => 'is_engaged_session', 'value' => 1 ),
        ),

        /*
         * The complement, as a count, so bounceRate is a ratio of two counts.
         * A bounce is a session that was not engaged.
         */
        'bouncedSessions' => array(
            'label'       => 'Bounced Sessions',
            'description' => 'Sessions that were not engaged.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'session_id',
            'condition'   => array( 'column' => 'is_engaged_session', 'value' => 0 ),
        ),

        'engagementRate' => array(
            'label'       => 'Engagement Rate',
            'description' => 'The share of sessions that were engaged.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'percentage',
            'numerator'   => 'engagedSessions',
            'denominator' => 'sessions',
            'precision'   => 4,
        ),

        'bounceRate' => array(
            'label'       => 'Bounce Rate',
            'description' => 'The share of sessions that were not engaged.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'percentage',
            'numerator'   => 'bouncedSessions',
            'denominator' => 'sessions',
            'precision'   => 4,
        ),

        'engagedSessionsPerUser' => array(
            'label'       => 'Engaged Sessions Per User',
            'description' => 'The average number of engaged sessions per user.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'engagedSessions',
            'denominator' => 'totalUsers',
            'precision'   => 2,
        ),
    ),
);
