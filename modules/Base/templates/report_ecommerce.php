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

<table width="100%">
    <TR>
        <TD valign="top" style="width:50%;">
            <div class="owa_reportSectionContent">
                <div class="section_header">Product Performance</div>
                <div style="min-width:250px;" id="productNameExplorer"></div>
                <script>

                var aurl = '<?php echo $this->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                                  'metrics' => 'lineItemRevenue',
                                                                  'dimensions' => 'productName',
                                                                  'sort' => 'lineItemRevenue-',
                                                                  'resultsPerPage' => 5,
                                                                  'format' => 'json'), true);?>';

                rsh = new OWA.resultSetExplorer('productNameExplorer');
                var link = '<?php echo $this->makeLink(array('do' => 'base.reportProductDetail', 'productName' => '%s'), true);?>';
                rsh.addLinkToColumn('productName', link, ['productName']);
                rsh.asyncQueue.push(['refreshGrid']);
                rsh.load(aurl, 'grid');
                </script>
            </div>

            <div class="owa_reportSectionContent">
                <div class="section_header">Sales Sources</div>
                <div style="min-width:300px;" id="sourceExplorer"></div>
                <script>
                var url = '<?php echo $this->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                              'metrics' => 'transactions,transactionRevenue',
                                                              'dimensions' => 'source',
                                                              'sort' => 'transactionsRevenue-',
                                                              'resultsPerPage' => 5,
                                                              'format' => 'json'), true);?>';

                rshre = new OWA.resultSetExplorer('sourceExplorer');
                var link = '<?php echo $this->makeLink(array('do' => 'base.reportSources', 'source' => '%s'), true);?>';
                rshre.addLinkToColumn('source', link, ['source']);
                rshre.asyncQueue.push(['refreshGrid']);
                rshre.load(url);
                </script>
            </div>
        </TD>

        <td valign="top">
            <div class="owa_reportSectionContent">
                <div class="section_header">Related Reports</div>
                <div class="relatedReports">
                <UL>
                    <li>
                        Item Level Analysis:
                        <a href="<?php echo $this->makeLink(array('do' => 'base.reportProducts'), true);?>">Product Name</a>,
                        <a href="<?php echo $this->makeLink(array('do' => 'base.reportProductSkus'), true);?>">SKU</a>,
                        <a href="<?php echo $this->makeLink(array('do' => 'base.reportProductCategories'), true);?>">Categories</a>
                    </li>
                    <li>
                        Purchase Patterns:
                        <a href="<?php echo $this->makeLink(array('do' => 'base.reportVisitsToPurchase'), true);?>">Visits to Purchase</a>,
                        <a href="<?php echo $this->makeLink(array('do' => 'base.reportDaysToPurchase'), true);?>">Days to Purchase</a>
                    </li>
                    <li>
                        Sales Trends:
                        <a href="<?php echo $this->makeLink(array('do' => 'base.reportAvgOrderValue'), true);?>">Average Order Value</a>,
                        <a href="<?php echo $this->makeLink(array('do' => 'base.reportRevenue'), true);?>">Total Revenue</a>,
                        <a href="<?php echo $this->makeLink(array('do' => 'base.reportEcommerceConversionRate'), true);?>">Conversion Rate</a>
                    </li>
                </UL>
                </div>
            </div>
        </td>
    </TR>
</table>

<?php require_once('js_report_templates.php');?>

