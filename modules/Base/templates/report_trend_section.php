<div class="owa_reportSectionContent">

    <div id="trend-chart"></div>


    <div id="trend-title" class="owa_reportHeadline"></div>
    <div id="trend-metrics" style="height:auto;width:auto;<?php if( isset( $pie ) ) {echo 'float:right';}?>"></div>
    <div style="clear:both;"></div>
    <script>

        var trendurl = '<?php echo $this->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                                    'metrics' => $metrics,
                                                                    'dimensions' => 'date',
                                                                    'sort' => 'date',
                                                                    'format' => 'json',
                                                                    'constraints' => $constraints
                                                                    ),true);?>';

        var trend = new OWA.resultSetExplorer('trend-chart');
        trend.options.sparkline.metric = 'visits';
        <?php if ($trendTitle):?>
        trend.asyncQueue.push(['renderTemplate', '<?php echo $trendTitle;?>', {d: trend}, 'replace', 'trend-title']);
        <?php endif;?>
        trend.asyncQueue.push(['makeAreaChart', [{x: 'date', y: '<?php echo $trendChartMetric; ?>'}], 'trend-chart']);
        trend.options.metricBoxes.width = '150px';
        trend.asyncQueue.push(['makeMetricBoxes' , 'trend-metrics']);
        trend.load(trendurl);

    </script>

</div>