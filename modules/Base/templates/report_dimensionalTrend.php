<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_reportSectionContent">
    
    <div id="trend-chart"></div>
    <div id="trend-title" class="owa_reportHeadline"></div>
    <div id="report-tabs">
        
        <?php foreach ($view->tabs as $k => $tab): ?>
        <div id="tab_<?php $view->out($k); ?>">
            
                <div id="<?php $view->out($k); ?>_trend-metrics" style="height:auto;width:auto;<?php if( $view->get( 'pie' ) ) {echo 'float:right';}?>"></div>
                <?php if ( $view->get('pie' ) ): ?>
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

<script type="text/javascript">
        
    // add tabs
    
    <?php foreach ($view->tabs as $k => $tab): ?>
    
    // adding tab for <?php $view->out($k, false);?>
    
    
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
    // add rse to tab
    tab.addRse('trend', trend);
    // dimensonal data object
    var dimurl = '<?php $_sort = $view->sort ?: $tab['sort'];
	    
	    echo $view->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                                'metrics' => $tab['metrics'],
                                                                'dimensions' => $view->dimensions,
                                                                'sort' => $_sort,
                                                                'resultsPerPage' => $view->resultsPerPage,
                                                                'format' => 'json',
                                                                'constraints' => $view->constraints
                                                                ),true);?>';
                                                                  
    var dim = new OWA.resultSetExplorer('<?php $view->out($k, false);?>_dimension-grid');
    dim.setDataLoadUrl(dimurl);
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
    // add dim object to tab
    tab.addRse('dim', dim);
    // add tab
    OWA.items['<?php echo $view->dom_id;?>'].addTab( tab );
    <?php endforeach;?>
    // create report tabs
    OWA.items['<?php echo $view->dom_id;?>'].createTabs();
    
</script>

