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
$owa_sets = $view->metrics
    ? array( '' => array() )
    : (array) $view->metricSets;

if ( ! $owa_sets ) {

    // No site, so no sets -- render once with whatever the report has.
    $owa_sets = array( '' => array() );
}
?>
<?php foreach ( $owa_sets as $owa_setKey => $owa_set ): ?>
<div class="owa_reportGrid" data-metric-set="<?php $view->out( $owa_setKey ); ?>">

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
    $owa_query     = (array) ( $owa_w['query'] ?? array() ) + array(
        'metrics'     => $owa_set['metrics'] ?? $view->metrics,
        'do'          => 'reports',
        'module'      => 'base',
        'version'     => 'v1',
        'format'      => 'json',
        'constraints' => $view->constraints,
    );
?>
    <div class="<?php echo \OWA\Core\ReportGrid::classesFor( $owa_w ); ?> owa_reportSectionContent">

<?php if ( ( $owa_w['type'] ?? '' ) === 'trend' ): ?>
        <div id="<?php $view->out( $owa_container ); ?>"></div>
        <div id="<?php $view->out( $owa_id ); ?>-title" class="owa_reportHeadline"></div>
        <div id="<?php $view->out( $owa_id ); ?>-metrics" style="height:auto;width:auto;"></div>
        <div style="clear:both;"></div>

        <script>
        var <?php echo $owa_url; ?> = '<?php echo $view->makeApiLink( $owa_query, true ); ?>';

        var <?php echo $owa_id; ?> = new OWA.resultSetExplorer('<?php $view->out( $owa_container, false ); ?>');
        <?php echo $owa_id; ?>.options.sparkline.metric = 'visits';
<?php if ( ! empty( $owa_w['title'] ) ): ?>
        <?php echo $owa_id; ?>.asyncQueue.push(['renderTemplate', '<?php echo $owa_w['title']; ?>', {d: <?php echo $owa_id; ?>}, 'replace', '<?php $view->out( $owa_id, false ); ?>-title']);
<?php endif; ?>
<?php
    // The set's chart metric, unless the widget pinned its own.
    $owa_chartMetric = $owa_w['chartMetric'] ?? ( $owa_set['chartMetric'] ?? '' );
?>
<?php if ( $owa_chartMetric !== '' ): ?>
        <?php echo $owa_id; ?>.asyncQueue.push(['makeAreaChart', [{x: 'date', y: '<?php $view->out( $owa_chartMetric, false ); ?>'}], '<?php $view->out( $owa_container, false ); ?>']);
<?php endif; ?>
        <?php echo $owa_id; ?>.options.metricBoxes.width = '150px';
        <?php echo $owa_id; ?>.asyncQueue.push(['makeMetricBoxes' , '<?php $view->out( $owa_id, false ); ?>-metrics']);

        <?php echo $owa_id; ?>.load(<?php echo $owa_url; ?>);
        </script>

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
     */
?>
<?php if ( ! empty( $owa_w['title'] ) ): ?>
        <div class="owa_reportSectionHeader"><?php $view->out( $owa_w['title'] ); ?></div>
<?php endif; ?>
        <div class="relatedReports">
            <ul>
<?php foreach ( (array) ( $owa_w['links'] ?? array() ) as $owa_link ): ?>
                <li>
                    <a href="<?php echo $view->makeLink( array(
                        'do' => 'base.report', 'reportId' => $owa_link['reportId'] ), true ); ?>"><?php
                        $view->out( $owa_link['label'] ); ?></a><?php
                        if ( ! empty( $owa_link['description'] ) ): ?> - <?php $view->out( $owa_link['description'] ); endif; ?>
                </li>
<?php endforeach; ?>
            </ul>
        </div>

<?php elseif ( ( $owa_w['type'] ?? '' ) === 'grid' ): ?>
        <div id="<?php $view->out( $owa_container ); ?>"></div>

        <script>
        var <?php echo $owa_url; ?> = '<?php echo $view->makeApiLink( $owa_query, true ); ?>';

        var <?php echo $owa_id; ?> = new OWA.resultSetExplorer('<?php $view->out( $owa_container, false ); ?>');
<?php if ( ! empty( $owa_w['link'] ) ): ?>
        var <?php echo $owa_id; ?>link = '<?php echo $view->makeLink( $owa_w['link']['template'], true ); ?>';
        <?php echo $owa_id; ?>.addLinkToColumn('<?php $view->out( $owa_w['link']['linkColumn'], false ); ?>', <?php echo $owa_id; ?>link, <?php echo $view->makeJson( (array) $owa_w['link']['valueColumns'] ); ?>);
<?php endif; ?>
<?php if ( ! empty( $owa_w['excludeColumns'] ) ): ?>
        <?php echo $owa_id; ?>.options.grid.excludeColumns = [<?php echo $owa_w['excludeColumns']; ?>];
<?php endif; ?>
        <?php echo $owa_id; ?>.asyncQueue.push(['refreshGrid']);

        <?php echo $owa_id; ?>.load(<?php echo $owa_url; ?>);
        </script>

<?php endif; ?>

    </div>
<?php endforeach; ?>

</div>
<?php endforeach; ?>

<?php require_once( 'js_report_templates.php' ); ?>
