<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php if (isset($view->dimension_properties) && $view->dimension_properties): ?>
<div class="owa_reportSectionContent">
    <?php echo $view->renderDimension($view->dimension_template, $view->dimension_properties);?>
</div>
<?php endif;?>

<div class="owa_reportSectionContent">

    <div id="trend-chart"></div>
    <div id="trend-title" class="owa_reportHeadline"></div>
    <div id="trend-metrics" style="height:auto;width:auto;<?php if( isset( $pie ) ) {echo 'float:right';}?>"></div>

    <?php if(isset($pie) && $pie): ?>
    <div id="pie" style="min-width:300px;"></div>
    <script>
    var hpurl = '<?php echo $view->makeApiLink(array(
                        'do'             => 'reports', 'module' => 'base', 'version' => 'v1',
                        'metrics'         => 'pageViews,visits,bounceRate',
                        'dimensions'     => 'hostName',
                        'sort'             => 'visits-',
                        'format'         => 'json',
                        'constraints' => urlencode($view->substituteValue('siteId==%s,','siteId'))),true);?>';

    hp = new OWA.resultSetExplorer('pie');
    hp.options.pieChart.dimension = '<?php echo $view->dimensions;?>';
    hp.options.pieChart.metric = 'visits';
    hp.setView('pie');
    hp.load(hpurl);

    </script>
    <?php endif; ?>

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
        <?php if (isset($view->trendChartMetric)): ?>
        trend.asyncQueue.push(['makeAreaChart', [{x: 'date', y: '<?php echo $view->trendChartMetric; ?>'}], 'trend-chart']);
        <?php endif; ?>
        trend.options.metricBoxes.width = '150px';
        trend.asyncQueue.push(['makeMetricBoxes' , 'trend-metrics']);

        trend.load(trendurl);

    </script>

</div>

<?php if ( $view->get( 'dimensions' ) ):?>
<div class="owa_reportSectionContent">

    <div id="dimension-grid"></div>

    <script>
        var dimurl = '<?php echo $view->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                                    'metrics' => $view->metrics,
                                                                    'dimensions' => $view->dimensions,
                                                                    'sort' => $view->sort,
                                                                    'resultsPerPage' => $view->resultsPerPage,
                                                                    'format' => 'json',
                                                                    'constraints' => $view->constraints
                                                                    ),true);?>';

        var dim = new OWA.resultSetExplorer('dimension-grid');

        <?php if (!empty($view->dimensionLink)):?>
        var link = '<?php echo $view->makeLink($view->dimensionLink['template'], true);?>';
        var values = <?php if (is_array($view->dimensionLink['valueColumns'])) {
                        $values = "[";
                        $i = 0;
                        $count = count($view->dimensionLink['valueColumns']);
                        foreach ($view->dimensionLink['valueColumns'] as $v) {
                            $values .= "'$v'";
                            if ($i < $count) {
                                $values .= ', ';
                            }
                            $i++;
                        }
                        $values .= "]";
                        echo $values;
                    } else {
                        echo "['".$view->dimensionLink['valueColumns']."']";
                    }
                    ?>;
        dim.addLinkToColumn('<?php echo $view->dimensionLink['linkColumn'];?>', link, values);
        <?php endif; ?>
        <?php if (!empty($view->excludeColumns)):?>
        dim.options.grid.excludeColumns = [<?php echo $view->excludeColumns;?>];
        <?php endif; ?>
        dim.asyncQueue.push(['refreshGrid']);
        dim.load(dimurl);
    </script>

</div>
<?php endif;?>

<?php require_once('js_report_templates.php');?>