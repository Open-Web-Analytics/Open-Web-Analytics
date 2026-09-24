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

        'domClicks' => array(
            'label'       => 'Clicks',
            'description' => 'The number of clicks on page elements.',
            'group'       => 'Site Usage',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => array( 'column' => 'event_type', 'value' => 'click' ),
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

        'keyEvents' => array(
            'label'       => 'Key Events',
            'description' => 'Conversions, counted from the rows the server materialised for them.',
            'group'       => 'Goals',
            'metric_type' => 'count',
            'data_type'   => 'integer',
            'column'      => 'id',
            'condition'   => array( 'column' => 'is_goal_event', 'value' => 1 ),
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
        'visits' => array(
            'label'       => 'Visits',
            'description' => 'The number of sessions.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'session_id',
        ),

        'uniqueVisitors' => array(
            'label'       => 'Unique Visitors',
            'description' => 'The number of distinct visitors.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'visitor_id',
        ),

        'newVisitors' => array(
            'label'       => 'New Visitors',
            'description' => 'Visitors whose first session this is.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'visitor_id',
            'condition'   => array( 'column' => 'prior_sessions', 'value' => 0 ),
        ),

        'returningVisitors' => array(
            'label'       => 'Returning Visitors',
            'description' => 'Visitors who had been here before.',
            'group'       => 'Site Usage',
            'metric_type' => 'distinct_count',
            'data_type'   => 'integer',
            'column'      => 'visitor_id',
            'condition'   => array( 'column' => 'prior_sessions', 'operator' => '>', 'value' => 0 ),
        ),

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
        'pagesPerVisit' => array(
            'label'       => 'Pages Per Visit',
            'description' => 'The average number of pages viewed per session.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'pageViews',
            'denominator' => 'visits',
            'precision'   => 2,
        ),

        'sessionsPerUser' => array(
            'label'       => 'Sessions Per Visitor',
            'description' => 'The average number of sessions per visitor.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'visits',
            'denominator' => 'uniqueVisitors',
            'precision'   => 2,
        ),

        'eventsPerSession' => array(
            'label'       => 'Events Per Visit',
            'description' => 'The average number of events recorded per session.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'decimal',
            'numerator'   => 'eventCount',
            'denominator' => 'visits',
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
        'averageEngagementTime' => array(
            'label'       => 'Average Engagement Time',
            'description' => 'Average time accrued per visit, in milliseconds.',
            'group'       => 'Site Usage',
            'metric_type' => 'ratio',
            'data_type'   => 'milliseconds',
            'numerator'   => 'totalEngagementTime',
            'denominator' => 'visits',
            'precision'   => 0,
        ),
    ),
);
