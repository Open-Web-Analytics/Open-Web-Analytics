<?php if (isset($dimension_properties) && $dimension_properties): ?>
<div class="owa_reportSectionContent">
    <?php echo $this->renderDimension($dimension_template, $dimension_properties);?>
</div>
<?php endif;?>

<div class="owa_reportSectionContent">

    <div id="trend-chart"></div>
    <div id="trend-title" class="owa_reportHeadline"></div>
    <div id="trend-metrics" style="height:auto;width:auto;<?php if( isset( $pie ) ) {echo 'float:right';}?>"></div>

    <?php if(isset($pie) && $pie): ?>
    <div id="pie" style="min-width:300px;"></div>
    <script>
    var hpurl = '<?php echo $this->makeApiLink(array(
                        'do'             => 'reports', 'module' => 'base', 'version' => 'v1',
                        'metrics'         => 'pageViews,visits,bounceRate',
                        'dimensions'     => 'hostName',
                        'sort'             => 'visits-',
                        'format'         => 'json',
                        'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))),true);?>';

    hp = new OWA.resultSetExplorer('pie');
    hp.options.pieChart.dimension = '<?php echo $dimensions;?>';
    hp.options.pieChart.metric = 'visits';
    hp.setView('pie');
    hp.load(hpurl);

    </script>
    <?php endif; ?>

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
        <?php if (isset($trendChartMetric)): ?>
        trend.asyncQueue.push(['makeAreaChart', [{x: 'date', y: '<?php echo $trendChartMetric; ?>'}], 'trend-chart']);
        <?php endif; ?>
        trend.options.metricBoxes.width = '150px';
        trend.asyncQueue.push(['makeMetricBoxes' , 'trend-metrics']);

        trend.load(trendurl);

    </script>

</div>

<?php if ( $this->get( 'dimensions' ) ):?>
<div class="owa_reportSectionContent">

    <div id="dimension-grid"></div>

    <script>
        var dimurl = '<?php echo $this->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                                    'metrics' => $metrics,
                                                                    'dimensions' => $dimensions,
                                                                    'sort' => $sort,
                                                                    'resultsPerPage' => $resultsPerPage,
                                                                    'format' => 'json',
                                                                    'constraints' => $constraints
                                                                    ),true);?>';

        var dim = new OWA.resultSetExplorer('dimension-grid');

        <?php if (!empty($dimensionLink)):?>
        var link = '<?php echo $this->makeLink($dimensionLink['template'], true);?>';
        var values = <?php if (is_array($dimensionLink['valueColumns'])) {
                        $values = "[";
                        $i = 0;
                        $count = count($dimensionLink['valueColumns']);
                        foreach ($dimensionLink['valueColumns'] as $v) {
                            $values .= "'$v'";
                            if ($i < $count) {
                                $values .= ', ';
                            }
                            $i++;
                        }
                        $values .= "]";
                        echo $values;
                    } else {
                        echo "['".$dimensionLink['valueColumns']."']";
                    }
                    ?>;
        dim.addLinkToColumn('<?php echo $dimensionLink['linkColumn'];?>', link, values);
        <?php endif; ?>
        <?php if (!empty($excludeColumns)):?>
        dim.options.grid.excludeColumns = [<?php echo $excludeColumns;?>];
        <?php endif; ?>
        dim.asyncQueue.push(['refreshGrid']);
        dim.load(dimurl);
    </script>

</div>
<?php endif;?>

<?php require_once('js_report_templates.php');?>