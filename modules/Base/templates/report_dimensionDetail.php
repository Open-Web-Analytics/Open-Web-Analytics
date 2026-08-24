<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php if ($view->dimension_properties): ?>
<div class="owa_reportSectionContent">
    <?php echo $view->renderDimension($view->dimension_template, $view->dimension_properties);?>
</div>
<?php endif;?>

<div class="owa_reportSectionContent">

    <div id="trend-chart" style="height:125px;width:auto;"></div>
    <div id="trend-title" class="owa_reportHeadline"></div>
    <div id="report-tabs">

        <?php foreach ($view->tabs as $k => $tab): ?>
        <div id="tab_<?php $view->out($k); ?>">

                <div id="<?php $view->out($k); ?>_trend-metrics" style="height:auto;width:auto;<?php if(isset($pie)) {echo 'float:right';}?>"></div>
                <?php if(isset($pie)): ?>
                <div id="pie" style="min-width:300px;"></div>
                <?php endif;?>
                <div class="spacer" style="clear:both; height:20px;"></div>
                <?php if (!$view->get('hideGrid')):?>
                <div id="<?php $view->out($k); ?>_dimension-grid"></div>
                <?php endif;?>

        </div>
        <?php endforeach; ?>
    </div>
</div>


<script>

    // add tabs
    <?php foreach ($view->tabs as $k => $tab): ?>

    var tab = new OWA.report.tab('tab_<?php $view->out($k, false);?>');
    tab.setLabel('<?php $view->out($tab['tab_label']);?>');
    // create trend and aggregate data resultSetExplorer objects
    var trendurl = '<?php echo $view->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                                'metrics' => $tab['metrics'],
                                                                'dimensions' => 'date',
                                                                'sort' => 'date',
                                                                'format' => 'json',
                                                                'constraints' => $view->constraints
                                                                ),true);?>';

    var trend = new OWA.resultSetExplorer('trend-chart');
    trend.setDataLoadUrl(trendurl);
    trend.options.sparkline.metric = 'visits';
    <?php if ($view->trendTitle):?>
    trend.asyncQueue.push(['renderTemplate', '<?php echo $view->trendTitle;?>', {d: trend}, 'replace', 'trend-title']);
    <?php endif;?>
    trend.asyncQueue.push(['makeAreaChart', [{x: 'date', y: '<?php if ( isset($tab['trendchartmetric'] ) ): echo $tab['trendchartmetric']; else: echo $view->trendChartMetric; endif; ?>'}], 'trend-chart']);
    trend.options.metricBoxes.width = '150px';
    trend.asyncQueue.push(['makeMetricBoxes' , '<?php $view->out($k, false);?>_trend-metrics']);
    tab.addRse('trend', trend);
    OWA.items['<?php echo $view->dom_id;?>'].addTab( tab );
    <?php endforeach;?>
    // create report tabs
    OWA.items['<?php echo $view->dom_id;?>'].createTabs();

</script>

