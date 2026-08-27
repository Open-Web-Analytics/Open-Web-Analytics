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
    ? array( '' => array() )
    : (array) $view->metricSets;

$owa_setKeysReal = (bool) array_filter( array_keys( $owa_sets ), function ( $k ) { return $k !== ''; } );

if ( ! $owa_sets ) {

    // No site, so no sets -- render once with whatever the report has.
    $owa_sets = array( '' => array() );
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
     * is deprecated. The first two carry one because the data behind them was
     * collected by fetching referring pages, which OWA no longer does, and a
     * report that silently renders nothing forever is worse than one that says
     * so.
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
<?php $owa_panelId = $owa_setKey === '' ? '' : 'tab_' . $owa_setKey; ?>
<div<?php if ( $owa_panelId ): ?> id="<?php $view->out( $owa_panelId ); ?>"<?php endif; ?> class="owa_reportGrid" data-metric-set="<?php $view->out( $owa_setKey ); ?>">
<?php $owa_rses = array(); ?>

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
    $owa_id        = $owa_prefix . (string) ( $owa_w['id'] ?? 'widget' );
    $owa_container = $owa_prefix . (string) ( $owa_w['container'] ?? ( $owa_w['id'] ?? 'widget' ) );
    $owa_url       = $owa_id . 'url';
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

    $owa_query     = (array) ( $owa_w['query'] ?? array() ) + array(
        'metrics'     => $owa_ownMetrics ? '' : ( $owa_set['metrics'] ?? $view->metrics ),
        'do'          => 'reports',
        'module'      => 'base',
        'version'     => 'v1',
        'format'      => 'json',
        'constraints' => $view->constraints,
    );

    /*
     * The metric a chart draws: the widget's own, or the metric set's.
     *
     * Computed once for both chart types rather than once each. There is
     * deliberately NO fallback to the first metric of the query: half the
     * shipped trends name no chartMetric and draw no area chart on purpose --
     * they are a headline and a row of boxes -- so a fallback would start
     * drawing charts on thirty-two reports that have never had one.
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
    $owa_trendDims = array_values( array_filter( array_map( 'trim',
        explode( ',', (string) ( $owa_query['dimensions'] ?? '' ) ) ) ) );

    $owa_chartSeries = array( array(
        'x' => $owa_trendDims[0] ?? 'date',
        'y' => $owa_chartMetric,
    ) );

    $owa_breakdown     = '';
    $owa_breakdownQuery = array();

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

        $owa_breakdownQuery = array();
    }
?>
    <div class="<?php echo \OWA\Core\ReportGrid::classesFor( $owa_w ); ?> owa_reportSectionContent">
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
    $owa_ownsTitle = ( $owa_w['type'] ?? '' ) === 'metric-boxes'
        && empty( $owa_w['showTitle'] );

    $owa_showTitle = ! $owa_ownsTitle
        && ! empty( $owa_w['title'] )
        && ( ! array_key_exists( 'showTitle', $owa_w ) || $owa_w['showTitle'] );
?>
<?php if ( $owa_showTitle ): ?>
        <div class="owa_reportSectionHeader"><?php $view->out( $owa_w['title'] ); ?></div>
<?php endif; ?>

<?php if ( in_array( ( $owa_w['type'] ?? '' ), array( 'trend', 'trend-card' ), true ) ): ?>
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
        <div id="<?php $view->out( $owa_container ); ?>"></div>
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

        <script>
        var <?php echo $owa_url; ?> = '<?php echo $view->makeApiLink( $owa_query, true ); ?>';

        var <?php echo $owa_id; ?> = new OWA.resultSetExplorer('<?php $view->out( $owa_container, false ); ?>');
        <?php echo $owa_id; ?>.setDataLoadUrl(<?php echo $owa_url; ?>);
        <?php echo $owa_id; ?>.options.sparkline.metric = 'visits';
<?php if ( ! empty( $owa_w['headline'] ) ): ?>
        <?php
            /*
             * A sentence with named slots, not a template. renderHeadline does
             * the substituting, so a definition carries no jqote and cannot
             * hand a template engine source of its own -- which is what has to
             * be true before a report definition can be authored by a user.
             *
             * json_encode, not a quoted echo: a headline is prose and will
             * contain apostrophes.
             */
        ?>
        <?php echo $owa_id; ?>.asyncQueue.push(['renderHeadline', <?php echo json_encode( $owa_w['headline'] ); ?>, '<?php $view->out( $owa_id, false ); ?>-title']);
<?php endif; ?>
<?php
?>
<?php if ( $owa_chartMetric !== '' ): ?>
        <?php echo $owa_id; ?>.asyncQueue.push(['makeAreaChart', <?php echo json_encode( $owa_chartSeries ); ?>, '<?php $view->out( $owa_container, false ); ?>']);
<?php endif; ?>
<?php if ( $owa_showMetricBoxes ): ?>
<?php if ( $owa_isTrendCard ): ?>
        <?php
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
        ?>
        <?php echo $owa_id; ?>.options.metricBoxes.minWidth = '100px';
<?php else: ?>
        <?php echo $owa_id; ?>.options.metricBoxes.width = '150px';
<?php endif; ?>
        <?php echo $owa_id; ?>.asyncQueue.push(['makeMetricBoxes' , '<?php $view->out( $owa_id, false ); ?>-metrics']);
<?php endif; ?>

<?php if ( $owa_breakdownQuery ): ?>
        <?php
            /*
             * The grid of the values the chart is broken out by, and the wiring
             * that makes the two one control surface.
             *
             * Declared after the trend so the trend variable exists when it is
             * handed over. The link is one call rather than a block of event
             * handlers here, because which control drives what is a decision
             * about the two objects and not about this report -- see
             * OWA.linkTrendToBreakdownGrid for what each control means.
             */
        ?>
        var <?php echo $owa_id; ?>Breakdownurl = '<?php echo $view->makeApiLink( $owa_breakdownQuery, true ); ?>';

        var <?php echo $owa_id; ?>Breakdown = new OWA.resultSetExplorer('<?php $view->out( $owa_id, false ); ?>-breakdown');
        <?php echo $owa_id; ?>Breakdown.setDataLoadUrl(<?php echo $owa_id; ?>Breakdownurl);
        <?php echo $owa_id; ?>Breakdown.asyncQueue.push(['refreshGrid']);

        OWA.linkTrendToBreakdownGrid(<?php echo $owa_id; ?>, <?php echo $owa_id; ?>Breakdown);
<?php endif; ?>

<?php if ( ! $owa_multiSet ): ?>
        <?php echo $owa_id; ?>.load();
<?php if ( $owa_breakdownQuery ): ?>
        <?php echo $owa_id; ?>Breakdown.load();
<?php endif; ?>
<?php endif; ?>
        </script>
<?php $owa_rses[ (string) ( $owa_w['id'] ?? 'widget' ) ] = $owa_id; ?>
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

        <script>
        var <?php echo $owa_url; ?> = '<?php echo $view->makeApiLink( $owa_query, true ); ?>';

        var <?php echo $owa_id; ?> = new OWA.resultSetExplorer('<?php $view->out( $owa_container, false ); ?>');
        <?php echo $owa_id; ?>.setDataLoadUrl(<?php echo $owa_url; ?>);
<?php if ( ! empty( $owa_w['boxWidth'] ) ): ?>
        <?php echo $owa_id; ?>.options.metricBoxes.width = '<?php $view->out( $owa_w['boxWidth'], false ); ?>';
<?php endif; ?>
        <?php
            // The label sits inside the box; see $owa_ownsTitle above. Encoded
            // rather than quoted -- it is prose and may carry an apostrophe.
        ?>
        <?php // Only a single-metric widget lends its title to the box; see $owa_ownsTitle. ?>
        <?php echo $owa_id; ?>.asyncQueue.push(['makeMetricBoxes', '', <?php echo json_encode( $owa_ownsTitle ? (string) ( $owa_w['title'] ?? '' ) : '' ); ?>]);

<?php if ( ! $owa_multiSet ): ?>
        <?php echo $owa_id; ?>.load();
<?php endif; ?>
        </script>
<?php $owa_rses[ (string) ( $owa_w['id'] ?? 'widget' ) ] = $owa_id; ?>

<?php elseif ( ( $owa_w['type'] ?? '' ) === 'pie' ): ?>
<?php
    /*
     * A share-of-total chart: one slice per value of the query's dimension.
     *
     * The dimension is NOT configured separately -- it is the one the widget
     * already queries, and naming it twice is a way for the two to disagree.
     */
?>
        <div id="<?php $view->out( $owa_container ); ?>"></div>

        <script>
        var <?php echo $owa_url; ?> = '<?php echo $view->makeApiLink( $owa_query, true ); ?>';

        var <?php echo $owa_id; ?> = new OWA.resultSetExplorer('<?php $view->out( $owa_container, false ); ?>');
        <?php echo $owa_id; ?>.setDataLoadUrl(<?php echo $owa_url; ?>);
        <?php echo $owa_id; ?>.options.pieChart.metric = '<?php $view->out( $owa_chartMetric, false ); ?>';
        <?php echo $owa_id; ?>.options.pieChart.dimension = '<?php $view->out( (string) ( $owa_query['dimensions'] ?? '' ), false ); ?>';
<?php if ( ! empty( $owa_w['valueLabels'] ) ): ?>
        <?php // Raw value -> label, so a boolean pie can read New/Repeat rather than No/Yes. ?>
        <?php echo $owa_id; ?>.options.pieChart.valueLabels = <?php echo json_encode( (object) $owa_w['valueLabels'] ); ?>;
<?php endif; ?>
<?php
    /*
     * NO chartWidth. A pie is as wide as the widget holding it and as tall as
     * its own option -- see OWA.pieChart.setupPieChart -- so a per-widget width
     * had nothing to set.
     *
     * It was not merely unused, it was DEAD: the template wrote it onto the
     * explorer's option bag, and the pie reads a different one and takes its
     * width from the DOM regardless. `traffic` carried chartWidth: '300px' and
     * drew at 619px for years.
     *
     * Removed rather than wired up, because wiring it would put back the thing
     * this was reported as: pies at different sizes on different reports.
     */
?>
        <?php echo $owa_id; ?>.asyncQueue.push(['makePieChart']);

<?php if ( ! $owa_multiSet ): ?>
        <?php echo $owa_id; ?>.load();
<?php endif; ?>
        </script>
<?php $owa_rses[ (string) ( $owa_w['id'] ?? 'widget' ) ] = $owa_id; ?>

<?php elseif ( ( $owa_w['type'] ?? '' ) === 'browser-badge' ): ?>
<?php
    /*
     * The browser's icon and family name, above a browser-detail report.
     *
     * The widget names its own template; the definition does not. It used to
     * be `dimension_template: "dimension_browser.php"` in the settings, which
     * is configuration naming a PHP file on disk -- the same class of problem
     * as a jqote string, and one that a user-authored definition must never be
     * able to say.
     */
?>
        <?php echo $view->renderDimension( 'dimension_browser.php',
            (array) ( $owa_w['properties'] ?? array() ) ); ?>

<?php elseif ( ( $owa_w['type'] ?? '' ) === 'referral-badge' ): ?>
<?php
    /*
     * The referring page, above a referral-detail report. Same shape as
     * browser-badge: the widget names its template, the definition supplies
     * only properties.
     *
     * It replaces `dimension_template: "dimension_referral.php"` AND the entity
     * lookup that filled it. That lookup fetched three columns -- page_title,
     * url and snippet. `url` is the request parameter, so it needs no lookup;
     * the other two were filled by the referral crawl, which is gone. page_title
     * is now the literal string "(not set)" that RefererHandlers writes as its
     * default, and snippet is empty on every row. Rendering them meant a panel
     * headed "(not set)" above a blank line.
     */
?>
        <?php echo $view->renderDimension( 'dimension_referral.php',
            (array) ( $owa_w['properties'] ?? array() ) ); ?>

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
<?php foreach ( (array) ( $owa_w['links'] ?? array() ) as $owa_link ): ?>
                <li>
                    <?php
                        /*
                         * Any parameters the link declares travel with it --
                         * a link to a report that is ABOUT something has to
                         * say what. Merged UNDER do/reportId so a declared
                         * param cannot rewrite the target.
                         */
                        $owa_link_params = array( 'do' => 'base.report',
                                                  'reportId' => $owa_link['reportId'] )
                            + (array) ( $owa_link['params'] ?? array() );
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
     * rule as browser-badge: a report definition may say WHAT a widget is
     * about, never which action to invoke or what to put in a credential.
     *
     * The overlay is reached in two hops. This link goes to the launcher on
     * OWA's own origin, which resolves the path to a real page and redirects
     * the browser there with an #owa_overlay fragment; the tracker on that page
     * reads the fragment and fetches the clicks.
     *
     * The api_url is an ORDINARY dimensional query now -- domClicks grouped by
     * clickX and clickY, constrained on the page -- so the overlay token binds
     * to `constraints`, a normal request parameter, rather than to a bespoke
     * report's document_id. Identical coordinates group, so a page with
     * hundreds of thousands of clicks answers with the few thousand distinct
     * points that actually get drawn.
     */
    $owa_hm_path = (string) ( $owa_w['pagePath'] ?? '' );

    if ( $owa_hm_path !== '' ):

        $owa_hm_constraints = 'pagePath==' . urlencode( $owa_hm_path );

        $owa_hm_api = $view->makeOverlayApiLink( array(
            'do'             => 'reports',
            'module'         => 'base',
            'version'        => 'v1',
            'metrics'        => 'domClicks',
            'dimensions'     => 'clickX,clickY',
            'constraints'    => $owa_hm_constraints,
            'resultsPerPage' => 1000,
            'format'         => 'json',
        ), 'constraints' );

        $owa_hm_url = $view->makeLink( array(
            'do'             => 'base.overlayLauncher',
            'pagePath'       => $owa_hm_path,
            'overlay_params' => base64_encode( $view->makeParamString( array(
                'action'  => 'loadHeatmap',
                'api_url' => $owa_hm_api,
            ), false, 'json' ) ),
        ), true );
?>
        <div class="relatedReports">
            <ul>
                <li>
                    <a href="<?php echo $owa_hm_url; ?>" target="_blank">Heatmap Overlay</a>
                     - click visualization map.
                </li>
            </ul>
        </div>
<?php endif; ?>

<?php elseif ( in_array( ( $owa_w['type'] ?? '' ), array( 'grid', 'grid-card' ), true ) ): ?>
        <?php
            /*
             * The BODY of the widget, named so the panel can push its footer
             * down. The explorer renders its control bar, its table and its
             * pager into this element, so it is the part that should take up
             * the slack when the panel is taller than the rows in it -- which
             * is what puts the pager on the panel's floor instead of floating
             * under the last row.
             */
        ?>
        <div id="<?php $view->out( $owa_container ); ?>" class="owa_widgetBody"></div>

        <script>
        var <?php echo $owa_url; ?> = '<?php echo $view->makeApiLink( $owa_query, true ); ?>';

        var <?php echo $owa_id; ?> = new OWA.resultSetExplorer('<?php $view->out( $owa_container, false ); ?>');
        <?php echo $owa_id; ?>.setDataLoadUrl(<?php echo $owa_url; ?>);
<?php if ( ( $owa_w['type'] ?? '' ) === 'grid-card' ): ?>
        <?php
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
        ?>
        <?php echo $owa_id; ?>.options.grid.showExplorerControls = false;
<?php endif; ?>
<?php if ( ! empty( $owa_w['link'] ) ): ?>
        var <?php echo $owa_id; ?>link = '<?php echo $view->makeLink( $owa_w['link']['template'], true ); ?>';
        <?php echo $owa_id; ?>.addLinkToColumn('<?php $view->out( $owa_w['link']['linkColumn'], false ); ?>', <?php echo $owa_id; ?>link, <?php echo $view->makeJson( (array) $owa_w['link']['valueColumns'] ); ?>);
<?php endif; ?>
<?php if ( ! empty( $owa_w['excludeColumns'] ) ): ?>
        <?php
            /*
             * Encoded, not interpolated.
             *
             * This was the one value in this template echoed raw into the
             * page, and the definitions carried their own JavaScript quoting
             * to suit it -- "'pageUrl'", quotes included. That made a report
             * definition able to emit arbitrary script, in the one file format
             * that is meant to become user-authorable. A list of column names
             * says the same thing and cannot say anything else.
             */
        ?>
        <?php echo $owa_id; ?>.options.grid.excludeColumns = <?php echo json_encode( array_values( (array) $owa_w['excludeColumns'] ) ); ?>;
<?php endif; ?>
<?php if ( ! empty( $owa_w['formatters'] ) ): ?>
        <?php
            /*
             * A column formatter is NAMED, not supplied. jqGrid resolves the
             * name against jQuery.fn.fmatter, the same way the built-in
             * `useServerFormatter` and `urlFormatter` defaults are resolved.
             *
             * The value this replaces was a JavaScript function, echoed raw
             * from a controller. Encoded here, and the name is checked against
             * ConfiguredReport::KNOWN_FORMATTERS before it gets this far, so a
             * definition can select a formatter but cannot become one.
             */
        ?>
        <?php echo $owa_id; ?>.options.grid.columnFormatters = <?php echo json_encode( (object) $owa_w['formatters'] ); ?>;
<?php endif; ?>
        <?php echo $owa_id; ?>.asyncQueue.push(['refreshGrid']);

<?php if ( ! $owa_multiSet ): ?>
        <?php echo $owa_id; ?>.load();
<?php endif; ?>
        </script>
<?php $owa_rses[ (string) ( $owa_w['id'] ?? 'widget' ) ] = $owa_id; ?>

<?php endif; ?>

<?php if ( ! empty( $owa_w['more'] ) ): ?>
        <?php
            /*
             * "View Full Report" -- a link from a summary widget to the report
             * that shows the whole thing.
             *
             * A property of the widget rather than a sibling report-links
             * widget, because it describes THIS widget. As a sibling it could
             * be reordered away from the grid it refers to and mean nothing.
             */
        ?>
        <div class="owa_genericHorizonalList owa_moreLinks">
            <ul><li><a href="<?php echo $view->makeLink( array(
                'do' => 'base.report', 'reportId' => $owa_w['more']['reportId'] ), true ); ?>"><?php
                $view->out( $owa_w['more']['label'] ?? 'View Full Report' ); ?></a></li></ul>
        </div>
<?php endif; ?>

    </div>
<?php endforeach; ?>

</div>
<?php if ( $owa_multiSet ): ?>
<script>
    // This set, and the widgets whose results belong to it. The tab loads them
    // when it becomes the one being looked at.
    var owaSet_<?php $view->out( $owa_setKey, false ); ?> = new OWA.report.tab('<?php $view->out( $owa_panelId, false ); ?>');
    owaSet_<?php $view->out( $owa_setKey, false ); ?>.setLabel('<?php $view->out( $owa_set['label'] ?? $owa_setKey ); ?>');
<?php foreach ( $owa_rses as $owa_rseName => $owa_rseVar ): ?>
    owaSet_<?php $view->out( $owa_setKey, false ); ?>.addRse('<?php $view->out( $owa_rseName, false ); ?>', <?php echo $owa_rseVar; ?>);
<?php endforeach; ?>
    OWA.items['<?php echo $view->dom_id; ?>'].addTab( owaSet_<?php $view->out( $owa_setKey, false ); ?> );
</script>
<?php endif; ?>
<?php endforeach; ?>

<?php if ( $owa_multiSet ): ?>
</div>
<script>
    OWA.items['<?php echo $view->dom_id; ?>'].createTabs();
</script>
<?php endif; ?>
