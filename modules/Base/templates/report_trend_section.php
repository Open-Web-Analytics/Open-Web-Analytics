<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_reportSectionContent">

    <div id="trend-chart"></div>


    <div id="trend-title" class="owa_reportHeadline"></div>
    <div id="trend-metrics" style="height:auto;width:auto;<?php if( isset( $pie ) ) {echo 'float:right';}?>"></div>
    <div style="clear:both;"></div>
    <script>

        var trendurl = '<?php echo $view->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                                    'metrics' => $view->metrics,
                                                                    'dimensions' => 'date',
                                                                    'sort' => 'date',
                                                                    'format' => 'json',
                                                                    'constraints' => $view->constraints
                                                                    ),true);?>';

        var trend = new OWA.resultSetExplorer('trend-chart');
        trend.options.sparkline.metric = 'visits';
        <?php if ($view->trendTitle):?>
        trend.asyncQueue.push(['renderTemplate', '<?php echo $view->trendTitle;?>', {d: trend}, 'replace', 'trend-title']);
        <?php endif;?>
        trend.asyncQueue.push(['makeAreaChart', [{x: 'date', y: '<?php echo $view->trendChartMetric; ?>'}], 'trend-chart']);
        trend.options.metricBoxes.width = '150px';
        trend.asyncQueue.push(['makeMetricBoxes' , 'trend-metrics']);
        trend.load(trendurl);

    </script>

</div>