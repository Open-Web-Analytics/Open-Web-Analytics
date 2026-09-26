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
         * GA4 reached the same shape: Exits is a metric there, paired with the
         * ordinary page dimension, and GA ships no exit-page dimension at all.
         *
         * NO exitRate. GA ships none either -- you divide Exits by Views in an
         * Exploration -- and a rate is the wrong thing to ship first here: a
         * session's last event is decided by ARRIVAL order (Cube\IsExitStep
         * over a window sorted on ts), so a late beacon puts is_exit on the
         * wrong row. A count carries that error visibly; a percentage presents
         * it as precision.
         */
        'exits' => array(
            'label'       => 'Exits',
            'description' => 'The number of times a session ended on this page.',
            'group'       => 'Site Usage',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => array( 'column' => 'is_exit', 'value' => 1 ),
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
         * Util.generateRandomGuid() builds it as a unix timestamp plus nine
         * random digits -- the same construction as the visitor id, so it is
         * already as unique as the visitor id is. Measured across 15,643
         * sessions of real history: zero session ids shared by more than one
         * visitor.
         *
         * Pairing is also actively worse here. COUNT(DISTINCT a, b) drops any
         * row where either column is NULL, and 48 of those sessions carry a
         * NULL visitor id -- so the pair under-counts by exactly 48 to guard
         * against a one-in-a-billion collision.
         *
         * GA pairs because GA must: its `ga_session_id` is the session-start
         * timestamp in seconds with no randomness at all. Ours is not that.
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
         * newVisitors has no `returningVisitors` twin, deliberately.
         *
         * GA has newUsers and no returning-users metric: you take totalUsers
         * and group it by the New/returning dimension. Two metrics splitting
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

        'ecommerceConversionRate' => array(
            'label'       => 'Ecommerce Conversion Rate',
            'description' => 'The share of sessions that completed a purchase.',
            'group'       => 'Ecommerce',
            'metric_type' => 'ratio',
            'data_type'   => 'percentage',
            'numerator'   => 'transactions',
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
         * ---- the per-USER ratios GA carries and we did not ----------------
         *
         * Every one is arithmetic over metrics that already exist, so they cost
         * a declaration each. GA's names are eventCountPerUser,
         * screenPageViewsPerUser, averagePurchaseRevenuePerUser and
         * averageEngagementTimePerUser; ours differ only where the underlying
         * metric does.
         *
         * A per-USER denominator answers a different question from a
         * per-session one -- "how much does a person do" against "how much
         * happens in a visit" -- which is why GA ships both and why naming the
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
         * counts. GA does the same, and for the same reason.
         *
         * No ordering caveat, unlike exits: this sums over a session, so which
         * event the pass calls last does not enter into it.
         */
        /*
         * PER SESSION, and the name says so. GA carries both
         * averageEngagementTimePerSession and a per-user one, and a bare
         * "average engagement time" does not say which denominator it used --
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
    ),
);
