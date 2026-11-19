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
return [

    'entity' => 'base.event',

    'metrics' => [

        // ---- every row --------------------------------------------------
        'eventCount' => [
            'label'       => 'Events',
            'description' => 'The total number of events.',
            'group'       => 'Site Usage',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
        ],

        /*
         * ---- counting SOME rows ----------------------------------------
         *
         * A `condition` restricts what is counted to the rows matching one
         * test, and it is the same scan: `sum(CASE WHEN ... THEN 1 ELSE 0
         * END)`, no subquery. Most of this vocabulary is "count the rows that
         * are X", which is what an event table makes cheap and what 1.x could
         * not express -- its `actions` counted one fact table.
         */
        'pageViews' => [
            'label'       => 'Page Views',
            'description' => 'The total number of pages viewed.',
            'group'       => 'Site Usage',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => [ 'column' => 'event_type', 'value' => 'page_view' ],
        ],

        /*
         * Exits: the last event of a session, counted on the page it happened
         * on. A METRIC, not a dimension, which is the whole point of it.
         *
         * An exit-page DIMENSION resolves to a page only on the session's last
         * row, so grouping by it puts every OTHER view of that page into one
         * anonymous bucket and the report cannot state an exit rate. So Exits is
         * a metric, paired with the ordinary page dimension, and there is no
         * exit-page dimension at all. Entrances likewise.
         *
         * THE SESSION'S LAST PAGE VIEW, by device sequence (Cube\IsExitStep):
         * not the last event of any kind, since a click after it happened on
         * the same page, and not by arrival, since a late beacon would move it.
         */
        'exits' => [
            'label'       => 'Exits',
            'description' => 'The number of sessions that ended on a page view. Grouped by page, the number that ended on each.',
            'group'       => 'Site Usage',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => [ 'column' => 'is_exit', 'value' => 1 ],
        ],

        /*
         * Of the times a page was viewed, the share that were a session's last.
         * Grouped by page it is each page's exit rate; ungrouped, exits over all
         * page views.
         */
        'exitRate' => [
            'label'       => 'Exit Rate',
            'description' => 'The share of page views that were the last in their session.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'percentage',
            'numerator'   => 'exits',
            'denominator' => 'pageViews',
            'precision'   => 4,
        ],

        /* The session's first page view, the mirror of exits (Cube\IsEntranceStep). */
        'entrances' => [
            'label'       => 'Entrances',
            'description' => 'The number of sessions that began on a page view. Grouped by page, the number that began on each.',
            'group'       => 'Site Usage',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => [ 'column' => 'is_entrance', 'value' => 1 ],
        ],

        /*
         * GOAL EVENTS: the rows Classes\GoalMarking adds, one for each goal event
         * definition an event meets (once per session, for a definition that
         * says so). An event meeting two definitions adds two.
         */
        'goalEvents' => [
            'label'       => 'Goal Events',
            'description' => 'The number of goal events. An event that meets several goal event definitions adds one for each.',
            'group'       => 'Goals',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => [ 'column' => 'is_goal_event', 'value' => 1 ],
        ],

        /*
         * What the goal events were worth: each carries its definition's value,
         * or the revenue of the purchase that met it, in minor units of the
         * currency beside it (EventRaw `value`). A goal event worth nothing
         * carries NULL and adds nothing.
         */
        'goalValue' => [
            'label'       => 'Goal Value',
            'description' => 'The total value of the goal events: each goal event definition\'s value, or the revenue of the purchase that met it.',
            'group'       => 'Goals',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'column'      => 'value',
            'condition'   => [ 'column' => 'is_goal_event', 'value' => 1 ],
        ],

        /*
         * FORMS. The tracker raises one form_start per form per page view,
         * including just before a submit that had no change event, so every
         * submit has a start.
         */
        'formStarts' => [
            'label'       => 'Form Starts',
            'description' => 'The number of times a visitor began filling in a form.',
            'group'       => 'Site Usage',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => [ 'column' => 'event_type', 'value' => 'form_start' ],
        ],

        'formSubmits' => [
            'label'       => 'Form Submits',
            'description' => 'The number of times a form was submitted.',
            'group'       => 'Site Usage',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => [ 'column' => 'event_type', 'value' => 'form_submit' ],
        ],

        /*
         * Submits are NOT deduplicated -- a form sent twice from one page view
         * is two submits against one start -- so this can pass 100%.
         */
        'formSubmitRate' => [
            'label'       => 'Form Submit Rate',
            'description' => 'Form submits as a share of form starts.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'percentage',
            'numerator'   => 'formSubmits',
            'denominator' => 'formStarts',
            'precision'   => 4,
        ],

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
        'transactions' => [
            'label'       => 'Transactions',
            'description' => 'The number of completed purchases.',
            'group'       => 'Ecommerce',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => [ 'column' => 'event_type', 'value' => 'purchase' ],
        ],

        'taxRevenue' => [
            'label'       => 'Tax',
            'description' => 'Total tax collected on completed purchases.',
            'group'       => 'Ecommerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'column'      => 'tax',
            'condition'   => [ 'column' => 'event_type', 'value' => 'purchase' ],
        ],

        'shippingRevenue' => [
            'label'       => 'Shipping',
            'description' => 'Total shipping charged on completed purchases.',
            'group'       => 'Ecommerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'column'      => 'shipping',
            'condition'   => [ 'column' => 'event_type', 'value' => 'purchase' ],
        ],

        'transactionRevenue' => [
            'label'       => 'Revenue',
            'description' => 'Total revenue from completed purchases.',
            'group'       => 'Ecommerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'column'      => 'revenue',
            'condition'   => [ 'column' => 'event_type', 'value' => 'purchase' ],
        ],

        // ---- counting distinct things -----------------------------------
        /*
         * `session_id` ALONE, not paired with the visitor.
         *
         * Util.generateRandomGuid() gives it 62 random bits: the expected
         * collisions among n session ids are n^2 / 2^63, a few hundredths over
         * a decade of a large site. Ids from before 2.0 are a unix timestamp
         * plus nine random digits -- about 30 random bits per second -- and
         * history keeps them.
         *
         * Pairing is also actively worse here. COUNT(DISTINCT a, b) drops any
         * row where either column is NULL, and some sessions carry a NULL
         * visitor id.
         *
         * Pairing is only necessary where a session id is a bare start timestamp
         * with no randomness in it. Ours is not.
         */
        'sessions' => [
            'label'       => 'Sessions',
            'description' => 'The number of sessions.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'session_id',
        ],

        'totalUsers' => [
            'label'       => 'Total Users',
            'description' => 'The number of distinct users.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'visitor_id',
        ],

        'newUsers' => [
            'label'       => 'New Users',
            'description' => 'Users whose first session this is.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'visitor_id',
            'condition'   => [ 'column' => 'prior_sessions', 'value' => 0 ],
        ],

        /*
         * Users who scrolled. A page view sends a scroll event only after the
         * visitor has scrolled past the first threshold, so any scroll event
         * counts; group by scrollDepth for how far they got.
         */
        'scrolledUsers' => [
            'label'       => 'Scrolled Users',
            'description' => 'The number of distinct users who scrolled a page.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'visitor_id',
            'condition'   => [ 'column' => 'event_type', 'value' => 'scroll' ],
        ],

        /*
         * newUsers has no `returningUsers` twin, deliberately.
         *
         * To count returning users you take totalUsers and group it by the
         * New/returning dimension: two metrics splitting one population would
         * be the boolean-twin shape the dimensions avoid.
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
         * A zero or missing denominator gives 0 (Metric::computeRatio()), as a
         * trend's empty interval does.
         */
        'pageViewsPerSession' => [
            'label'       => 'Pages Per Session',
            'description' => 'The average number of pages viewed per session.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'pageViews',
            'denominator' => 'sessions',
            'precision'   => 2,
        ],

        /*
         * ---- refunds -------------------------------------------------------
         *
         * A refund is its own event, the amount refunded in `revenue` as a
         * positive number. transactionRevenue stays what was sold; netRevenue
         * is that less what was refunded.
         */
        'refunds' => [
            'label'       => 'Refunds',
            'description' => 'The number of refunds.',
            'group'       => 'Ecommerce',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => [ 'column' => 'event_type', 'value' => 'refund' ],
        ],

        'refundAmount' => [
            'label'       => 'Refund Amount',
            'description' => 'The amount refunded, excluding tax and shipping.',
            'group'       => 'Ecommerce',
            'metric_type' => 'sum',
            'data_type'   => 'currency',
            'column'      => 'revenue',
            'condition'   => [ 'column' => 'event_type', 'value' => 'refund' ],
        ],

        'netRevenue' => [
            'label'       => 'Net Revenue',
            'description' => 'Revenue less refunds, excluding tax and shipping.',
            'group'       => 'Ecommerce',
            'metric_type' => 'difference',
            'data_type'   => 'currency',
            'minuend'     => 'transactionRevenue',
            'subtrahend'  => 'refundAmount',
        ],

        'revenuePerTransaction' => [
            'label'       => 'Average Order Value',
            'description' => 'Revenue divided by the number of purchases.',
            'group'       => 'Ecommerce',
            'metric_type' => 'ratio',
            'data_type'   => 'currency',
            'numerator'   => 'transactionRevenue',
            'denominator' => 'transactions',
            'precision'   => 0,
        ],

        'revenuePerSession' => [
            'label'       => 'Revenue Per Session',
            'description' => 'Revenue divided by the number of sessions.',
            'group'       => 'Ecommerce',
            'metric_type' => 'ratio',
            'data_type'   => 'currency',
            'numerator'   => 'transactionRevenue',
            'denominator' => 'sessions',
            'precision'   => 0,
        ],

        /*
         * Sessions with at least one purchase. A session that bought twice is
         * one purchasing session and two transactions, and the conversion rate
         * is about sessions.
         */
        'purchasingSessions' => [
            'label'       => 'Purchasing Sessions',
            'description' => 'The number of sessions that included a purchase.',
            'group'       => 'Ecommerce',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'session_id',
            'condition'   => [ 'column' => 'event_type', 'value' => 'purchase' ],
        ],

        /*
         * PURCHASING SESSIONS over sessions, not transactions over sessions,
         * which would read 100% for two purchases in one of two sessions.
         */
        'ecommerceConversionRate' => [
            'label'       => 'Ecommerce Conversion Rate',
            'description' => 'The share of sessions that completed a purchase.',
            'group'       => 'Ecommerce',
            'metric_type' => 'ratio',
            'data_type'   => 'percentage',
            'numerator'   => 'purchasingSessions',
            'denominator' => 'sessions',
            'precision'   => 4,
        ],

        /*
         * Goal events per session, off the flag the event row already carries.
         * A DECIMAL, not a percentage: it counts goal events, not sessions that
         * had one, so it passes 1 wherever a session has two.
         */
        'goalEventsPerSession' => [
            'label'       => 'Goal Events Per Session',
            'description' => 'The average number of goal events per session.',
            'group'       => 'Goals',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'goalEvents',
            'denominator' => 'sessions',
            'precision'   => 2,
        ],

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
        'eventCountPerUser' => [
            'label'       => 'Events Per User',
            'description' => 'The average number of events per user.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'eventCount',
            'denominator' => 'totalUsers',
            'precision'   => 2,
        ],

        'pageViewsPerUser' => [
            'label'       => 'Page Views Per User',
            'description' => 'The average number of pages viewed per user.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'pageViews',
            'denominator' => 'totalUsers',
            'precision'   => 2,
        ],

        'averageEngagementTimePerUser' => [
            'label'       => 'Average Engagement Time Per User',
            'description' => 'Average time accrued per user, in milliseconds.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'milliseconds',
            'numerator'   => 'totalEngagementTime',
            'denominator' => 'totalUsers',
            'precision'   => 0,
        ],

        'revenuePerUser' => [
            'label'       => 'Revenue Per User',
            'description' => 'Revenue divided by the number of users.',
            'group'       => 'Ecommerce',
            'metric_type' => 'ratio',
            'data_type'   => 'currency',
            'numerator'   => 'transactionRevenue',
            'denominator' => 'totalUsers',
            'precision'   => 0,
        ],

        'goalEventsPerUser' => [
            'label'       => 'Goal Events Per User',
            'description' => 'The average number of goal events per user.',
            'group'       => 'Goals',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'goalEvents',
            'denominator' => 'totalUsers',
            'precision'   => 2,
        ],

        'sessionsPerUser' => [
            'label'       => 'Sessions Per User',
            'description' => 'The average number of sessions per user.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'sessions',
            'denominator' => 'totalUsers',
            'precision'   => 2,
        ],

        'eventsPerSession' => [
            'label'       => 'Events Per Session',
            'description' => 'The average number of events recorded per session.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'eventCount',
            'denominator' => 'sessions',
            'precision'   => 2,
        ],

        /*
         * A NAME SAYS WHICH IT IS. This is a total and the one below is an
         * average, and the pair is unreadable if either is called plain
         * "Engagement Time".
         */
        'totalEngagementTime' => [
            'label'       => 'Total Engagement Time',
            'description' => 'Time accrued on pages, in milliseconds, including time on the last page of a session.',
            'group'       => 'Site Usage',
            'metric_type' => 'sum',
            'data_type'   => 'milliseconds',
            'column'      => 'engagement_msec',
        ],

        /*
         * Engagement time is accrued on the DEVICE and sent, so the last page counts
         * -- a span between a session's first and last beacon would score a
         * single-pageview visit 0 however long it was read.
         *
         * No ordering caveat, unlike exits: this sums over a session, so which event
         * the pass calls last does not enter into it.
         */
        /*
         * PER SESSION, and the name says so. There is a per-user one too, and a
         * bare "average engagement time" does not say which denominator it used.
         */
        'averageEngagementTimePerSession' => [
            'label'       => 'Average Engagement Time Per Session',
            'description' => 'Average time accrued per session, in milliseconds.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'milliseconds',
            'numerator'   => 'totalEngagementTime',
            'denominator' => 'sessions',
            'precision'   => 0,
        ],

        /*
         * Engagement time per page view. Grouped by pagePath it is the time
         * spent on each page: every event carries its page, so a page's
         * engagement is the sum over the rows that name it.
         */
        'averageEngagementTimePerPageView' => [
            'label'       => 'Average Engagement Time Per Page View',
            'description' => 'Average time accrued per page view, in milliseconds.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'milliseconds',
            'numerator'   => 'totalEngagementTime',
            'denominator' => 'pageViews',
            'precision'   => 0,
        ],

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
        'engagedSessions' => [
            'label'       => 'Engaged Sessions',
            'description' => 'Sessions with ten seconds or more of engagement time, two or more page views, or a goal event.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'session_id',
            'condition'   => [ 'column' => 'is_engaged_session', 'value' => 1 ],
        ],

        /*
         * The complement, as a count, so bounceRate is a ratio of two counts.
         * A bounce is a session that was not engaged.
         */
        'bouncedSessions' => [
            'label'       => 'Bounced Sessions',
            'description' => 'Sessions that were not engaged.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'session_id',
            'condition'   => [ 'column' => 'is_engaged_session', 'value' => 0 ],
        ],

        'engagementRate' => [
            'label'       => 'Engagement Rate',
            'description' => 'The share of sessions that were engaged.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'percentage',
            'numerator'   => 'engagedSessions',
            'denominator' => 'sessions',
            'precision'   => 4,
        ],

        'bounceRate' => [
            'label'       => 'Bounce Rate',
            'description' => 'The share of sessions that were not engaged.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'percentage',
            'numerator'   => 'bouncedSessions',
            'denominator' => 'sessions',
            'precision'   => 4,
        ],

        'engagedSessionsPerUser' => [
            'label'       => 'Engaged Sessions Per User',
            'description' => 'The average number of engaged sessions per user.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'engagedSessions',
            'denominator' => 'totalUsers',
            'precision'   => 2,
        ],
    ],
];
