<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/**
 * A report's widgets, laid out in the grid.
 *
 * Each widget names the element it renders into and the variable its result set
 * is held in. Both are declared rather than derived: the ids are what OWA.report
 * and anything remembering per-widget state bind to, so a conversion that
 * renamed them would move every one of them at once, and only a browser would
 * notice.
 *
 * No script runs here. Each widget hands its description to the reporting
 * bundle in a <script type="application/json" data-ui="report-widget:config">,
 * a metric set its links and tab in data-ui="report-widgets:set", and a report
 * drawn per set the report whose tabs are built in data-ui="report-widgets:tabs".
 * owa.reportWidgets.js builds them in document order and publishes each
 * explorer under its variable's name, as the inline scripts did.
 */
$owa_widgets = (array) $view->widgets;

/*
 * Does this report use the site's metric sets, or does it measure one fixed
 * way?
 *
 * A report that DECLARES metrics has said how it measures: Web Pages is page
 * views and visits, and it is not "page views, measured by e-commerce". It
 * renders once. A report that declares none is the other kind -- one dimension
 * the site measures several ways -- and renders once per set the site offers.
 *
 * Today that distinction is implicit in which subview a report uses, tabbed or
 * not. Making it follow from what the definition says is what lets the two
 * kinds share one renderer, and it explains the dead configuration the
 * conversion found: 21 of the 29 multi-set reports declare metrics that never
 * reach a query, because their sets always overrode them.
 */
/*
 * A USER-AUTHORED report renders once, never per metric set.
 *
 * The site's metric sets are a property of the site, and a shipped report that
 * declares no metrics of its own opts into them -- that is how `pages` gets its
 * Site Usage / e-commerce / goal tabs. A custom report is the other kind by
 * construction: its author chose every widget and what each one measures, and
 * a report-level metric set if they wanted one.
 *
 * Left to inherit, a custom report grew a tab per site metric set showing the
 * same widgets in each -- and worse, silently showed NOTHING. In multi-set mode
 * widgets deliberately do not load themselves; they are registered with the tab
 * machinery, which loads whichever tab is active. So a custom report rendered
 * its containers and never fetched a row into any of them.
 */
$owa_authored = (bool) $view->get( 'custom_report_id' );

$owa_sets = ( $view->metrics || $owa_authored )
    ? [ '' => [] ]
    : (array) $view->metricSets;

$owa_setKeysReal = (bool) array_filter( array_keys( $owa_sets ), function ( $k ) { return $k !== ''; } );

if ( ! $owa_sets ) {

    // No site, so no sets -- render once with whatever the report has.
    $owa_sets = [ '' => [] ];
}

/*
 * A report rendered per metric set needs a way to move between them, and the
 * sets have to load lazily -- only the one being looked at should query.
 *
 * OWA.report already does both: a tab object owns a set's result-set
 * explorers, addTab registers it, and createTabs builds the control and loads
 * whichever set is active, reloading on switch. So the widgets are registered
 * with it rather than loaded directly.
 *
 * The control is drawn as tabs today. That is owa.report.js's business; this
 * template's job is to say which widgets belong to which set.
 */
$owa_multiSet = ! $view->metrics && ! $owa_authored
    && count( $owa_sets ) > 0 && $owa_setKeysReal;
?>
<?php
    /*
     * WHAT YOU CAN DO TO THIS REPORT IS NOT DRAWN HERE.
     *
     * There was a command bar between the title and the first widget, holding
     * one link. Two headers, one above the other, saying one thing -- and the
     * bar's border read as the top of the first widget rather than the bottom
     * of the header.
     *
     * "Edit report" is a title action now: Report::renderCustom() declares it
     * and report.php draws it as an icon on the title itself. That also gets it
     * the capability check the bar never had -- viewing a custom report is
     * deliberately wider than editing one, so this bar was offering a reader a
     * link to a refusal.
     */
?>
<?php if ( ! empty( $view->deprecated['message'] ) ): ?>
<?php
    /*
     * A report that is still here but no longer filling.
     *
     * Above the metric-set loop, so it is drawn once for the report rather
     * than once per set -- it describes the report, not a way of measuring it.
     *
     * `notice` is the house banner class from owa.css, the same mechanism
     * msgs.php and the scheduler nag use; owa_reportDeprecated only tightens
     * it for report scale. OWA has one banner, not a per-report one.
     *
     * Deliberately generic: the renderer neither knows nor cares WHY a report
     * is deprecated.
     */
?>
<div class="notice owa_reportDeprecated" role="status">
    <?php $view->out( $view->deprecated['message'] ); ?>
</div>
<?php endif; ?>

<?php if ( $owa_multiSet ): ?>
<div id="report-tabs">
<?php endif; ?>

<?php foreach ( $owa_sets as $owa_setKey => $owa_set ): ?>
<?php
    // A set's name, as the page's script and element ids may hold it.
    $owa_setVar  = preg_replace( '/[^A-Za-z0-9_]/', '_', (string) $owa_setKey );
    $owa_panelId = $owa_setKey === '' ? '' : 'tab_' . $owa_setVar;
?>
<div<?php if ( $owa_panelId ): ?> id="<?php $view->out( $owa_panelId ); ?>"<?php endif; ?> class="owa_reportGrid" data-metric-set="<?php $view->out( $owa_setKey ); ?>">
<?php $owa_rses = []; ?>
<?php
    /*
     * The trend and the grid of this set that are linked: the trend draws a
     * line for each of the top rows the grid is showing. The first plain trend
     * (not broken out, not declining it) and the first full-width grid.
     */
    $owa_plotTrend = '';
    $owa_plotGrid  = '';
?>

<?php foreach ( $owa_widgets as $owa_w ): ?>
<?php
    /*
     * A widget renders for every set unless it names the ones it is for.
     * Absence means "whatever is being viewed"; naming sets means "only
     * these" -- which is how a report shows, say, a revenue widget only
     * alongside the e-commerce metrics.
     */
    if ( ! empty( $owa_w['metricSets'] )
         && ! in_array( $owa_setKey, (array) $owa_w['metricSets'], true ) ) {
        continue;
    }

    /*
     * Containers and receivers are per set, because the widget is. Two sets
     * rendering into one element would have the later one overwrite the
     * earlier, and only one of them would ever be visible.
     */
    $owa_prefix    = $owa_setKey === '' ? '' : $owa_setKey . '_';
    /*
     * The id becomes a global's name (owa.reportWidgets.js publishes the
     * widget's explorer under it), and the container an element id. Both come from the report's
     * definition -- a saved custom report's, written by whoever may edit
     * reports and shown to everyone it is shared with -- so they are cut to
     * what a name may hold rather than trusted.
     */
    $owa_id        = preg_replace( '/[^A-Za-z0-9_]/', '_', $owa_prefix . (string) ( $owa_w['id'] ?? 'widget' ) );
    $owa_id        = preg_match( '/^[0-9]/', $owa_id ) ? 'w' . $owa_id : $owa_id;
    $owa_container = preg_replace( '/[^A-Za-z0-9_-]/', '_', $owa_prefix . (string) ( $owa_w['container'] ?? ( $owa_w['id'] ?? 'widget' ) ) );
    // A widget's own query wins, so a widget CAN ask for different metrics --
    // none does today, and the report-wide value is what a metric set replaces.
    /*
     * A TREND CARD NEVER INHERITS A METRIC SET.
     *
     * Every other widget falls back to the set being viewed, which is what
     * makes one report show its dimension measured three ways. A card is the
     * other kind of thing: half a row, with the metrics its author chose above
     * the chart, and a set would replace those with three to six whose boxes do
     * not fit the width the type exists to be.
     *
     * It cannot fail silently, either -- CustomReports::OWN_METRIC_TYPES
     * refuses a card that names none, rather than letting it inherit.
     */
    $owa_ownMetrics = ( $owa_w['type'] ?? '' ) === 'trend-card';

    $owa_query     = (array) ( $owa_w['query'] ?? [] ) + [
        'metrics'     => $owa_ownMetrics ? '' : ( $owa_set['metrics'] ?? $view->metrics ),
        'do'          => 'reports',
        'module'      => 'base',
        'version'     => 'v1',
        'format'      => 'json',
        'constraints' => $view->constraints,
    ];

    /*
     * The metric a chart draws: the widget's own, or the metric set's.
     *
     * Computed once for both chart types rather than once each. There is
     * deliberately NO fallback to the first metric of the query: half the
     * shipped trends name no chartMetric and draw no area chart on purpose --
     * they are a row of metric boxes and nothing else -- so a fallback would
     * start drawing charts on thirty-two reports that have never had one.
     *
     * A widget that DOES need a chart therefore has to name its metric, which
     * is why a pie may not inherit a report metric set. See
     * CustomReports::SINGLE_FIELD_TYPES.
     */
    $owa_chartMetric = (string) ( $owa_w['chartMetric'] ?? ( $owa_ownMetrics ? '' : ( $owa_set['chartMetric'] ?? '' ) ) );

    /*
     * ...except a trend CARD, which falls back to its first metric.
     *
     * The rule above exists because half the shipped trends name no chart
     * metric and draw no chart on purpose -- a fallback would start drawing
     * charts on thirty-two reports that have never had one. No trend card
     * predates this type, so there is nothing that could be changed by it, and
     * a card whose whole shape is a chart should not render as an empty box
     * because its author left one field alone.
     */
    if ( $owa_chartMetric === '' && $owa_ownMetrics ) {

        $owa_chartMetric = trim( (string) strtok( (string) $owa_query['metrics'], ',' ) );
    }

    /*
     * ONE METRIC, over time, optionally BROKEN OUT by one dimension.
     *
     * A trend's dimensions are the x axis first and the breakdown second, so
     * the query's own list is what says which is which -- `date` alone is the
     * filled area a trend has always been, and `date,medium` is a line per
     * medium over that same area.
     *
     * Built HERE, while $owa_query is still being assembled, because a
     * broken-out trend has to change it -- see resultsPerPage below.
     */
    if ( in_array( ( $owa_w['type'] ?? '' ), [ 'trend', 'trend-card' ], true ) ) {

        $owa_query = \OWA\Core\ConfiguredReport::trendQuery( $owa_query, $view->period_obj ?? null );
    }

    $owa_trendDims = array_values( array_filter( array_map( 'trim',
        explode( ',', (string) ( $owa_query['dimensions'] ?? '' ) ) ) ) );

    $owa_chartSeries = [ [
        'x' => $owa_trendDims[0] ?? 'date',
        'y' => $owa_chartMetric,
    ] ];

    $owa_breakdown     = '';
    $owa_breakdownQuery = [];

    if ( ( $owa_w['type'] ?? '' ) === 'trend' && isset( $owa_trendDims[1] ) ) {

        $owa_breakdown = $owa_trendDims[1];

        $owa_chartSeries[0]['series'] = $owa_breakdown;

        /*
         * ENOUGH ROWS TO BE RIGHT.
         *
         * A broken-out trend is one row per (date, value) pair, and the chart
         * sums those rows to draw its total and ranks them to pick its six
         * lines. Both are wrong if the result set was paginated -- and
         * makeApiLink carries the report's state, so this query would otherwise
         * inherit whatever page size the reader last used on a grid.
         *
         * A date-only trend never noticed: one row per day is inside any page
         * size. This is a bound, not a guarantee -- a dimension with thousands
         * of values over a long period still exceeds it, and the honest answer
         * there is a top-N in SQL rather than a bigger number here.
         */
        $owa_query['resultsPerPage'] = 1000;

        /*
         * THE ROWS BEHIND THE LINES.
         *
         * A trend broken out by a dimension draws a line per value, and the
         * question a reader asks next is always the same one: which values, and
         * how much each. That is a grid, so a broken-out trend grows one under
         * its boxes -- the same question at two levels of detail.
         *
         * A SECOND query, not a second view of the first. They need different
         * rows: the trend needs one per (date, value) to have a shape over
         * time, the grid needs one per value to be a ranking. Deriving the
         * ranking from the trend's rows would mean summing them in the browser,
         * which is wrong the moment the 1000-row bound above is reached -- and
         * silently wrong, because the sum still looks like a number.
         *
         * The x dimension is dropped and everything else travels: same metrics,
         * same constraints, so the two are measuring the same thing.
         */
        $owa_breakdownQuery = $owa_query;

        $owa_breakdownQuery['dimensions'] = $owa_breakdown;

        /*
         * Ordered by what the chart draws, descending, so the rows at the top
         * of the grid are the lines at the top of the chart. `-` suffixed is
         * how the reporting API spells descending; see the shipped grids.
         *
         * Falls back to the first metric when a trend names no chart metric --
         * a grid still has to be ordered by something, and the first metric is
         * the one its first numeric column shows.
         */
        $owa_breakdownSort = $owa_chartMetric !== ''
            ? $owa_chartMetric
            : trim( (string) strtok( (string) $owa_breakdownQuery['metrics'], ',' ) );

        $owa_breakdownQuery['sort'] = $owa_breakdownSort . '-';

        // A page of rows, not the thousand the chart needs. The grid pages.
        $owa_breakdownQuery['resultsPerPage'] = (int) ( $owa_w['breakdownRows'] ?? 25 );
    }

    /*
     * A CARD SHOWS TEN ROWS unless it says otherwise.
     *
     * The reporting API defaults to twenty-five, which is a full-width table's
     * number. A card is a quarter or half of a row and its height IS its row
     * count, so cards sitting beside each other need the same one or the row
     * comes out ragged -- which is the same reason they share a width.
     */
    if ( ( $owa_w['type'] ?? '' ) === 'grid-card'
         && ! isset( $owa_query['resultsPerPage'] ) ) {

        $owa_query['resultsPerPage'] = \OWA\Core\ConfiguredReport::DEFAULT_CARD_ROWS;
    }

    /*
     * A trend can decline the grid. Nothing shipped does today -- it is here
     * because `content` will want it: its trend is broken out by pagePath and
     * it already carries a Top Pages card showing those same rows.
     */
    if ( array_key_exists( 'showBreakdownGrid', $owa_w ) && ! $owa_w['showBreakdownGrid'] ) {

        $owa_breakdownQuery = [];
    }
?>
    <div class="<?php $view->out( \OWA\Core\ReportGrid::classesFor( $owa_w ) ); ?> owa_reportSectionContent" data-ui="widget:<?php $view->out( (string) ( $owa_w['id'] ?? 'widget' ) ); ?>">
<?php
    /*
     * A widget's title is its name -- "Transaction Roster". Whether it is
     * DISPLAYED is a separate choice: a widget can be named without the name
     * being drawn, which is what a report builder listing widgets needs, and
     * what a report wants when the surrounding layout already says what the
     * thing is.
     *
     * Shown by default when there is one -- unless the widget draws the title
     * itself. A metric box renders its label inside the box, above the number,
     * so the generic header would print the same name twice.
     */
    /*
     * A metric box renders its label INSIDE the box, above the number, so a
     * metric-boxes widget normally keeps its title for that and the generic
     * section header is suppressed -- otherwise the same name prints twice.
     * `traffic` is that case three times over: three widgets, one metric each,
     * each title naming the metric it measures.
     *
     * `showTitle: true` says the title is a SECTION HEADING instead. `goals`
     * needs it: its panel draws one box per goal the site has configured, and
     * labelling every box "Goal Performance" would say nothing about which
     * goal. Declared rather than inferred from the metric count -- a site with
     * exactly one goal resolves to exactly one metric, so counting cannot tell
     * a panel from a box.
     */
    /*
     * ...and only with ONE metric. Several boxes each labelled "Totals" say
     * nothing about which is which, so with more than one each box keeps its
     * metric's name and the title is the heading over them.
     */
    $owa_ownsTitle = ( $owa_w['type'] ?? '' ) === 'metric-boxes'
        && empty( $owa_w['showTitle'] )
        && count( array_filter( array_map( 'trim',
            explode( ',', (string) ( $owa_w['query']['metrics'] ?? '' ) ) ) ) ) <= 1;

    $owa_showTitle = ! $owa_ownsTitle
        && ! empty( $owa_w['title'] )
        && ( ! array_key_exists( 'showTitle', $owa_w ) || $owa_w['showTitle'] );
?>
<?php
    /*
     * "View Full Report" -- a link from a summary widget to the report that
     * shows the whole thing. On the heading's line when there is a heading,
     * under the widget when there is not.
     *
     * A property of the widget rather than a sibling report-links widget,
     * because it describes THIS widget. As a sibling it could be reordered
     * away from the grid it refers to and mean nothing.
     *
     * A card's is always at its foot, under the Show more bar: the rows grow
     * down to it (OWA.dataGrid showMore()).
     */
    $owa_more = ! empty( $owa_w['more'] );
    $owa_moreAtFoot = $owa_more
        && ( ( $owa_w['type'] ?? '' ) === 'grid-card' || ! $owa_showTitle );
?>
<?php if ( $owa_showTitle ): ?>
        <div class="owa_reportSectionHeader"><h2 class="owa_reportSectionTitle"><?php $view->out( $owa_w['title'] ); ?></h2><?php
            if ( $owa_more && ! $owa_moreAtFoot ): ?><span class="owa_moreLinks"><a href="<?php echo $view->makeLink( [
                'do' => 'base.report', 'reportId' => $owa_w['more']['reportId'] ], true ); ?>"><?php
                $view->out( isset( $owa_w['more']['label'] ) ? \OWA\Module\Base\Classes\CustomReports::shownMoreLabel( $owa_w['more']['label'] ) : \OWA\Core\CoreAPI::t( 'View Full Report' ) ); ?></a></span><?php endif; ?></div>
<?php endif; ?>

<?php if ( in_array( ( $owa_w['type'] ?? '' ), [ 'trend', 'trend-card' ], true ) ): ?>
<?php
    /*
     * The boxes, one per metric in the query.
     *
     * On by default -- a dimensional report's trend has always drawn them, and
     * they are how a metric set makes itself visible. `traffic` turns them off:
     * it measures one metric and already draws three boxes of its own beside
     * the chart, so a fourth repeating the total is noise.
     */
    $owa_showMetricBoxes = ! array_key_exists( 'showMetricBoxes', $owa_w ) || $owa_w['showMetricBoxes'];

    /*
     * A CARD READS TOP DOWN FROM THE NUMBER.
     *
     * A full-width trend leads with its chart because the chart is the thing --
     * a shape across a whole row, with the totals underneath as detail. Half a
     * row is not enough chart for that to be true: what a reader takes from a
     * card is the figure, and the plot is the shape behind it. So the boxes go
     * first and the chart sits under them.
     *
     * Which is also why the boxes lose their borders here. Two bordered boxes
     * above a plot read as three stacked panels; without them the figure and
     * its shape read as one thing, which is what a card is.
     */
    $owa_isTrendCard = ( $owa_w['type'] ?? '' ) === 'trend-card';
?>
<?php if ( $owa_isTrendCard && $owa_showMetricBoxes ): ?>
        <div id="<?php $view->out( $owa_id ); ?>-metrics" class="owa_trendCardMetrics" style="height:auto;width:auto;"></div>
<?php endif; ?>
        <?php
            /*
             * The plot's own element, named so a card can hand it the height
             * the panel has left. The area chart reads this element's height
             * when it sizes its plot, so growing it is all it takes.
             */
        ?>
        <div id="<?php $view->out( $owa_container ); ?>" class="owa_trendChart"></div>
        <div id="<?php $view->out( $owa_id ); ?>-title" class="owa_reportHeadline"></div>
<?php if ( ! $owa_isTrendCard && $owa_showMetricBoxes ): ?>
        <div id="<?php $view->out( $owa_id ); ?>-metrics" style="height:auto;width:auto;"></div>
<?php endif; ?>
        <div style="clear:both;"></div>
<?php if ( $owa_breakdownQuery ): ?>
        <?php
            /*
             * UNDER the boxes, because it is the detail behind them: the chart
             * is the shape, the boxes are the totals, the grid is the rows they
             * are made of. Reading down the widget goes from least to most
             * detailed.
             *
             * A card never has one: it cannot be broken out, so there are no
             * values to list. See CustomReports::FIXED_DIMENSION_EXTRA.
             */
        ?>
        <div id="<?php $view->out( $owa_id ); ?>-breakdown" class="owa_trendBreakdown"></div>
<?php endif; ?>

<?php
    /*
     * The widget, as owa.reportWidgets.js builds it: an explorer published as
     * `$owa_id` with its URL as `$owa_id`url, the options it is given and the
     * commands it runs on its rows. In a multi-set report the tab loads it.
     */
    $owa_desc = [
        'name'      => $owa_id,
        'container' => $owa_container,
        'url'       => $view->makeApiLink( $owa_query, true ),
        'options'   => [ 'sparkline' => [ 'metric' => 'sessions' ] ],
        'queue'     => [],
        'load'      => ! $owa_multiSet,
    ];

    if ( $owa_showTitle || $owa_isTrendCard ) {

        // The widget's own heading, or the card's figure, already says what the chart is.
        $owa_desc['options']['areaChart']['showTitle'] = false;
    }

    if ( $owa_isTrendCard ) {

        // A card's chart is the shape behind its figures: a line, not a filled area.
        $owa_desc['options']['areaChart']['fill'] = false;
    }

    if ( $owa_chartMetric !== '' ) {

        $owa_desc['queue'][] = [ 'makeAreaChart', $owa_chartSeries, $owa_container ];
    }

    if ( $owa_showMetricBoxes ) {

        /*
         * A CARD'S BOXES SHARE THE ROW; they are not pinned to a width.
         *
         * A full-width trend fixes each box at 150px because its row is
         * wide enough that a fixed size reads as deliberate. A card has
         * half a row, and the number of metrics is the AUTHOR'S -- so a
         * fixed width decides for them how many fit, and four became two
         * rows of two.
         *
         * Flexing from a lower floor instead: four fit a half-width row,
         * five wrap. That is a bound rather than a cap -- it is the point
         * at which a box stops being readable, not a limit on what may be
         * asked for.
         */
        if ( $owa_isTrendCard ) {

            $owa_desc['options']['metricBoxes']['minWidth'] = '100px';

        } else {

            $owa_desc['options']['metricBoxes']['width'] = '150px';
        }

        $owa_desc['queue'][] = [ 'makeMetricBoxes', $owa_id . '-metrics' ];
    }

    if ( $owa_breakdownQuery ) {

        /*
         * The grid of the values the chart is broken out by, published as
         * `$owa_id`Breakdown, and the wiring that makes the two one control
         * surface: OWA.linkTrendToBreakdownGrid, called once both exist. See
         * it for what each control means.
         */
        $owa_desc['breakdown'] = [
            'name'      => $owa_id . 'Breakdown',
            'container' => $owa_id . '-breakdown',
            'url'       => $view->makeApiLink( $owa_breakdownQuery, true ),
            'queue'     => [ [ 'refreshGrid' ] ],
        ];
    }
?>
        <script type="application/json" data-ui="report-widget:config"><?php echo $view->scriptJson( $owa_desc ); ?></script>
<?php $owa_rses[ (string) ( $owa_w['id'] ?? 'widget' ) ] = $owa_id; ?>
<?php
    if ( $owa_plotTrend === '' && ( $owa_w['type'] ?? '' ) === 'trend' && $owa_breakdown === ''
            && $owa_chartMetric !== '' && ( $owa_w['plotGridRows'] ?? true ) ) {

        $owa_plotTrend = $owa_id;
    }
?>
<?php if ( $owa_breakdownQuery ): ?>
<?php
    /*
     * Registered as a result set of the tab in its own right, so a metric set
     * that is not being looked at does not query for its grid either. A widget
     * contributing two result sets is why these are keyed by name rather than
     * counted.
     */
?>
<?php $owa_rses[ (string) ( $owa_w['id'] ?? 'widget' ) . '_breakdown' ] = $owa_id . 'Breakdown'; ?>
<?php endif; ?>

<?php elseif ( ( $owa_w['type'] ?? '' ) === 'metric-boxes' ): ?>
<?php
    /*
     * One labelled box per metric in the query, with a sparkline.
     *
     * Separate from `trend`, which also draws boxes but as part of a chart:
     * these stand alone and each one measures its OWN rows. `traffic` is the
     * case -- three boxes, same metric, one per medium -- which is why a
     * widget can carry a constraint of its own.
     */
?>
        <div id="<?php $view->out( $owa_container ); ?>"></div>

<?php
    $owa_desc = [
        'name'      => $owa_id,
        'container' => $owa_container,
        'url'       => $view->makeApiLink( $owa_query, true ),
        'options'   => [],
        /*
         * The label sits inside the box; see $owa_ownsTitle above. Only a
         * single-metric widget lends its title to the box.
         */
        'queue'     => [ [ 'makeMetricBoxes', '', $owa_ownsTitle ? (string) ( $owa_w['title'] ?? '' ) : '' ] ],
        'load'      => ! $owa_multiSet,
    ];

    if ( ! empty( $owa_w['boxWidth'] ) ) {

        $owa_desc['options']['metricBoxes']['width'] = (string) $owa_w['boxWidth'];
    }

    $owa_desc['options'] = (object) $owa_desc['options'];
?>
        <script type="application/json" data-ui="report-widget:config"><?php echo $view->scriptJson( $owa_desc ); ?></script>
<?php $owa_rses[ (string) ( $owa_w['id'] ?? 'widget' ) ] = $owa_id; ?>

<?php elseif ( ( $owa_w['type'] ?? '' ) === 'pie' ): ?>
<?php
    /*
     * A share chart: each value of the query's dimension as its share of the
     * metric, ranked, with the rest as Other (OWA.shareChart). The type is
     * still called `pie` because saved reports name it.
     *
     * The dimension is NOT configured separately -- it is the one the widget
     * already queries, and naming it twice is a way for the two to disagree.
     *
     * Its query carries the report's state WITHOUT the comparison: a share
     * chart shows the period only, and a compared query costs two more
     * queries for numbers it would not draw.
     */
    $owa_shareState = array_values( array_diff( \OWA\Core\View::LINK_STATE_KEYS,
        [ 'compare', 'compareStartDate', 'compareEndDate' ] ) );
?>
        <div id="<?php $view->out( $owa_container ); ?>" class="owa_widgetBody owa_shareBody"></div>

<?php
    $owa_desc = [
        'name'      => $owa_id,
        'container' => $owa_container,
        'url'       => $view->makeApiLink( $owa_query, $owa_shareState ),
        'options'   => [ 'shareChart' => [
            'metric'    => (string) $owa_chartMetric,
            'dimension' => (string) ( $owa_query['dimensions'] ?? '' ),
        ] ],
        'queue'     => [ [ 'makeShareChart' ] ],
        'load'      => ! $owa_multiSet,
    ];

    if ( ! empty( $owa_w['valueLabels'] ) ) {

        // Raw value -> label, so a boolean dimension can read New/Repeat rather than No/Yes.
        $owa_desc['options']['shareChart']['valueLabels'] = (object) $owa_w['valueLabels'];
    }

    if ( ! empty( $owa_w['ownConstraints'] ) ) {

        $owa_desc['options']['shareChart']['ownConstraints'] = (string) $owa_w['ownConstraints'];
    }
?>
        <script type="application/json" data-ui="report-widget:config"><?php echo $view->scriptJson( $owa_desc ); ?></script>
<?php $owa_rses[ (string) ( $owa_w['id'] ?? 'widget' ) ] = $owa_id; ?>

<?php elseif ( ( $owa_w['type'] ?? '' ) === 'report-links' ): ?>
<?php
    /*
     * A list of links to other reports.
     *
     * Several reports are bespoke largely because they hand-write a block of
     * these in HTML. Written as markup nobody can check them, and two on the
     * Content report have pointed at the wrong report for years -- "Feeds"
     * goes to Referral Link Text and "Entry & Exits" goes to Referrals. As
     * data, a test can resolve every target.
     *
     * No query and no result-set explorer: this widget renders from its own
     * declaration.
     *
     * The title is drawn by the shared header above, not here. Drawing its own
     * printed the name twice -- and ignored `showTitle`, so a report-links
     * title could not be hidden at all.
     */
?>
        <div class="relatedReports">
            <ul>
<?php foreach ( (array) ( $owa_w['links'] ?? [] ) as $owa_link ): ?>
                <li>
                    <?php
                        /*
                         * Any parameters the link declares travel with it --
                         * a link to a report that is ABOUT something has to
                         * say what. Merged UNDER do/reportId so a declared
                         * param cannot rewrite the target.
                         */
                        $owa_link_params = [ 'do' => 'base.report',
                                                  'reportId' => $owa_link['reportId'] ]
                            + (array) ( $owa_link['params'] ?? [] );
                    ?>
                    <a href="<?php echo $view->makeLink( $owa_link_params, true ); ?>"><?php
                        $view->out( $owa_link['label'] ); ?></a><?php
                        if ( ! empty( $owa_link['description'] ) ): ?> - <?php $view->out( $owa_link['description'] ); endif; ?>
                </li>
<?php endforeach; ?>
            </ul>
        </div>

<?php elseif ( ( $owa_w['type'] ?? '' ) === 'heatmap-link' ): ?>
<?php
    /*
     * "Heatmap Overlay" -- opens the tracked page itself with the click
     * overlay switched on.
     *
     * The widget builds the URL; the definition supplies only the page. Same
     * rule the retired badge widgets followed: a report definition may say WHAT a widget is
     * about, never which action to invoke or what to put in a credential.
     *
     * The overlay is reached in two hops. This link goes to the launcher on
     * OWA's own origin, which resolves the path to a real page and redirects
     * the browser there with an #owa_overlay fragment; the tracker on that page
     * reads the fragment and fetches the clicks.
     *
     * The api_url is an ORDINARY dimensional query now -- eventCount grouped by
     * where on the element and where on the page each click landed
     * (OverlayLauncher::HEATMAP_DIMENSIONS), constrained on the page and to
     * clicks -- so the token binds to `constraints`, a normal request
     * parameter, rather than to a bespoke report's document_id.
     */
    $owa_hm_path = (string) ( $owa_w['pagePath'] ?? '' );

    if ( $owa_hm_path !== '' ):

        /*
         * CLICKS ONLY, said as a constraint.
         *
         * This asked for `metrics=domClicks`, which no metric declares -- so the
         * name never resolved and the heatmap's fetch could not run. The filter it
         * wanted is an ordinary constraint on the event, and eventCount already
         * counts rows: without it, grouping by clickX and clickY would fold every
         * non-click row of the page into one bucket at NULL,NULL.
         *
         * Comma-separated, which is what parseConstraintsString() splits on. The
         * path is escaped as a constraint value (a comma in it does not split
         * the pair), then urlencoded for the link.
         */
        $owa_hm_constraints = 'pagePath==' . urlencode( \OWA\Module\Base\Classes\ResultSetManager::constraintValue( $owa_hm_path ) )
            . ',eventName==click';

        $owa_hm_api = $view->makeOverlayApiLink( [
            'do'             => 'reports',
            'module'         => 'base',
            'version'        => 'v1',
            'metrics'        => 'eventCount',
            'dimensions'     => \OWA\Module\Base\Controller\OverlayLauncher::HEATMAP_DIMENSIONS,
            'constraints'    => $owa_hm_constraints,
            'resultsPerPage' => 1000,
            'format'         => 'json',
        ], 'constraints' );

        $owa_hm_url = $view->makeLink( [
            'do'             => 'base.overlayLauncher',
            'pagePath'       => $owa_hm_path,
            'overlay_params' => base64_encode( $view->makeParamString( [
                'action'  => 'loadHeatmap',
                'api_url' => $owa_hm_api,
            ], false, 'json' ) ),
        ], true );
?>
        <div class="relatedReports">
            <ul>
                <li>
                    <?php $view->outMarkup( \OWA\Core\CoreAPI::t( '%s - click visualization map.' ),
                        '<a target="_blank" href="' . $owa_hm_url . '">' . $view->escape( \OWA\Core\CoreAPI::t( 'Heatmap Overlay' ) ) . '</a>' ); ?>
                </li>
            </ul>
        </div>
<?php endif; ?>

<?php elseif ( in_array( ( $owa_w['type'] ?? '' ), [ 'grid', 'grid-card' ], true ) ): ?>
        <?php
            /*
             * The BODY of the widget, named so the panel can push its footer
             * down. The explorer renders its control bar, its table and its
             * pager (a card's Show more bar) into this element, so it is the
             * part that should take up the slack when the panel is taller than
             * the rows in it. That puts a card's bar on the panel's floor
             * instead of under its last row.
             */
        ?>
        <div id="<?php $view->out( $owa_container ); ?>" class="owa_widgetBody"></div>
<?php
    /*
     * `compare: false`: the report's state without the comparison. A grid of
     * single visits has no compare period to match its rows against.
     */
    $owa_gridState = ( $owa_w['compare'] ?? true ) === false
        ? array_values( array_diff( \OWA\Core\View::LINK_STATE_KEYS, [ 'compare', 'compareStartDate', 'compareEndDate' ] ) )
        : true;
?>

<?php
    $owa_desc = [
        'name'      => $owa_id,
        'container' => $owa_container,
        'url'       => $view->makeApiLink( $owa_query, $owa_gridState ),
        'options'   => [],
        'queue'     => [ [ 'refreshGrid' ] ],
        'load'      => ! $owa_multiSet,
    ];

    if ( ( $owa_w['type'] ?? '' ) === 'grid-card' ) {

        /*
         * A CARD, not an explorer.
         *
         * A grid-card is one metric against one dimension at a quarter of
         * the row's width. The bar above a grid is a dimension picker and a
         * filter -- both of which add columns, and there is no room for a
         * second column here. Offering them would be offering the reader a
         * way to make the widget stop fitting.
         *
         * Explore is what the full-width grid is for, which is why a grid
         * cannot be narrowed and a card cannot be widened: the two ARE the
         * layouts their controls need.
         */
        $owa_desc['options']['grid']['showExplorerControls'] = false;
        $owa_desc['options']['grid']['compact']              = true;
    }

    if ( ! empty( $owa_w['link'] ) ) {

        /*
         * explorer.addLinkToColumn( column, url, values, except ), the URL
         * published as `$owa_id`link. The values are makeJson()'s object --
         * strings, entities decoded, reserved names mapped -- read back so
         * they travel inside this description; `except` is a list.
         */
        $owa_desc['columnLink'] = [
            'column' => (string) $owa_w['link']['linkColumn'],
            'url'    => $view->makeLink( $owa_w['link']['template'], true ),
            'values' => json_decode( $view->makeJson( (array) $owa_w['link']['valueColumns'] ) ),
            'except' => array_values( array_map( 'strval', (array) ( $owa_w['link']['except'] ?? [] ) ) ),
        ];
    }

    if ( ! empty( $owa_w['excludeColumns'] ) ) {

        /*
         * A list of column names. The definitions once carried their own
         * JavaScript quoting -- "'pageUrl'", quotes included -- because this
         * was echoed raw into the page, which let a report definition emit
         * arbitrary script.
         */
        $owa_desc['options']['grid']['excludeColumns'] = array_values( (array) $owa_w['excludeColumns'] );
    }

    if ( ! empty( $owa_w['formatters'] ) ) {

        /*
         * A column formatter is NAMED, not supplied. jqGrid resolves the
         * name against jQuery.fn.fmatter, the same way the built-in
         * `useServerFormatter` and `urlFormatter` defaults are resolved.
         * The name is checked against ConfiguredReport::KNOWN_FORMATTERS
         * before it gets this far, so a definition can select a formatter
         * but cannot become one.
         */
        $owa_desc['options']['grid']['columnFormatters'] = (object) $owa_w['formatters'];
    }

    if ( ! empty( $owa_w['ownConstraints'] ) ) {

        $owa_desc['options']['grid']['ownConstraints'] = (string) $owa_w['ownConstraints'];
    }

    /*
     * The row actions, each as a link built here -- so it carries the report's
     * Property and period like every other link on the page -- with a
     * placeholder per parameter the grid fills from the row. See
     * ConfiguredReport::ROW_ACTIONS for what may be named.
     */
    $owa_rowActions = [];

    foreach ( \OWA\Core\ConfiguredReport::resolveRowActions( $owa_w ) as $owa_ra ) {

        $owa_ra_params = isset( $owa_ra['reportId'] )
            ? [ 'do' => 'base.report', 'reportId' => $owa_ra['reportId'] ]
            : [ 'do' => (string) $owa_ra['action'] ];

        foreach ( array_keys( (array) $owa_ra['params'] ) as $owa_ra_param ) {

            $owa_ra_params[ $owa_ra_param ] = '__OWA_ROW_' . $owa_ra_param . '__';
        }

        $owa_rowActions[] = [
            'id'        => $owa_ra['id'],
            // Translated where it is drawn (OWA.translate()).
            'label'     => (string) $owa_ra['label'],
            'href'      => $view->makeLink( $owa_ra_params, true ),
            'params'    => (object) $owa_ra['params'],
            'first'     => (string) ( $owa_ra['first'] ?? '' ),
            'newWindow' => ! empty( $owa_ra['newWindow'] ),
        ];
    }

    if ( $owa_rowActions ) {

        $owa_desc['options']['grid']['rowActions'] = $owa_rowActions;
    }

    $owa_desc['options'] = (object) $owa_desc['options'];
?>
        <script type="application/json" data-ui="report-widget:config"><?php echo $view->scriptJson( $owa_desc ); ?></script>
<?php $owa_rses[ (string) ( $owa_w['id'] ?? 'widget' ) ] = $owa_id; ?>
<?php if ( $owa_plotGrid === '' && ( $owa_w['type'] ?? '' ) === 'grid' ) { $owa_plotGrid = $owa_id; } ?>

<?php endif; ?>

<?php if ( $owa_moreAtFoot && ( $owa_w['type'] ?? '' ) === 'grid-card' ): ?>
        <div class="owa_moreLinks owa_cardFoot"><a data-ui="card-full-report" href="<?php echo $view->makeLink( [
            'do' => 'base.report', 'reportId' => $owa_w['more']['reportId'] ], true ); ?>"><?php
            $view->out( isset( $owa_w['more']['label'] ) ? \OWA\Module\Base\Classes\CustomReports::shownMoreLabel( $owa_w['more']['label'] ) : \OWA\Core\CoreAPI::t( 'Full report' ) ); ?></a></div>
<?php elseif ( $owa_moreAtFoot ): ?>
        <div class="owa_moreLinks"><a href="<?php echo $view->makeLink( [
            'do' => 'base.report', 'reportId' => $owa_w['more']['reportId'] ], true ); ?>"><?php
            $view->out( isset( $owa_w['more']['label'] ) ? \OWA\Module\Base\Classes\CustomReports::shownMoreLabel( $owa_w['more']['label'] ) : \OWA\Core\CoreAPI::t( 'View Full Report' ) ); ?></a></div>
<?php endif; ?>

    </div>
<?php endforeach; ?>

</div>
<?php
    /*
     * After the set's widgets: the trend that draws the grid's top rows, and in
     * a multi-set report the set's tab, published as owaSet_<set>, with the
     * result sets it loads when it becomes the one being looked at. The result
     * sets are pairs, in order: a widget id may be numeric.
     */
    $owa_setDesc = [ 'report' => (string) $view->dom_id ];

    if ( $owa_plotTrend !== '' && $owa_plotGrid !== '' ) {

        $owa_setDesc['link'] = [ $owa_plotTrend, $owa_plotGrid ];
    }

    if ( $owa_multiSet ) {

        $owa_setDesc['tab'] = [
            'name'  => 'owaSet_' . $owa_setVar,
            'panel' => $owa_panelId,
            'label' => (string) ( $owa_set['label'] ?? $owa_setKey ),
            'rses'  => array_map( null, array_map( 'strval', array_keys( $owa_rses ) ), array_values( $owa_rses ) ),
        ];
    }
?>
<?php if ( isset( $owa_setDesc['link'] ) || isset( $owa_setDesc['tab'] ) ): ?>
<script type="application/json" data-ui="report-widgets:set"><?php echo $view->scriptJson( $owa_setDesc ); ?></script>
<?php endif; ?>
<?php endforeach; ?>

<?php if ( $owa_multiSet ): ?>
</div>
<script type="application/json" data-ui="report-widgets:tabs"><?php echo $view->scriptJson( [ 'report' => (string) $view->dom_id ] ); ?></script>
<?php endif; ?>
