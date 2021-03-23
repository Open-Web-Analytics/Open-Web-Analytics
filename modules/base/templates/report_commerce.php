<div class="owa_reportSectionContent">
    <div id="trend-chart" style="height:125px;width:auto;"></div>
    <div class="owa_reportHeadline" id="content-headline"></div>
    <div id="trend-metrics"></div>
</div>

<div class="clear"></div>
<BR>

<table style="width:100%;margin-top:;">
    <tr>
        <td valign="top" style="width:50%;">

        <div class="owa_reportSectionContent">


            <div class="owa_reportSectionContent" style="min-width:350px;">
                <div class="owa_reportSectionHeader">Products</div>

                <div id="top-products"></div>
                <div class="owa_genericHorizonalList owa_moreLinks">
                    <UL>
                        <LI>
                            <a href="<?php echo $this->makeLink(array('do' => 'base.reportProducts'), true);?>">View Full Report &raquo;</a>
                        </LI>
                    </UL>
                </div>
            </div>

        </td>

        <td valign="top" style="width:50%;">

            <div class="owa_reportSectionContent" style="min-width:350px;">
                <div class="owa_reportSectionHeader">Traffic Sources</div>
                <div id="top-sources"></div>
                <div class="owa_genericHorizonalList owa_moreLinks">
                    <UL>
                        <LI>
                            <a href="<?php echo $this->makeLink(array('do' => 'base.reportSources'), true);?>">View Full Report &raquo;</a>
                        </LI>
                    </UL>
                </div>
            </div>

        </td>
    </tr>
</table>

<script>
//OWA.setSetting('debug', true);

var aurl = '<?php echo $this->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1', 
                                                'metrics' => 'visits,transactions,transactionRevenue,revenuePerVisit,revenuePerTransaction,ecommerceConversionRate',
                                                'dimensions' => 'date',
                                                'sort' => 'date',
                                                'format' => 'json',
                                                'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))), true);?>';

OWA.items.rsh = new OWA.resultSetExplorer('trend-chart');
OWA.items.rsh.options.metricBoxes.width = '125px';
OWA.items.rsh.asyncQueue.push(['makeAreaChart', [{x:'date',y:'transactions'}]]);
OWA.items.rsh.asyncQueue.push(['makeMetricBoxes', 'trend-metrics']);
OWA.items.rsh.asyncQueue.push(['renderTemplate','#headline-template', {data: OWA.items.rsh}, 'replace', 'content-headline']);
OWA.items.rsh.load(aurl);

var topproductsurl = '<?php echo $this->makeApiLink(array(
                                                'do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                'metrics' => 'lineItemQuantity,lineItemRevenue',
                                                'dimensions' => 'productName',
                                                'sort' => 'lineItemRevenue-',
                                                'format' => 'json',
                                                'resultsPerPage' => 25,
                                                'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))), true);?>';

OWA.items.topproducts = new OWA.resultSetExplorer('top-products');
OWA.items.topproducts.addLinkToColumn('productName', '<?php echo $this->makeLink(array(
                                                                        'do' => 'base.reportProductDetail',
                                                                        'productName' => '%s'
                                                                    ),true);?>', ['productName']);
OWA.items.topproducts.asyncQueue.push(['refreshGrid']);
OWA.items.topproducts.load(topproductsurl);

var topsourcesurl = '<?php echo $this->makeApiLink(array(
                                                'do' => 'reports', 'module' => 'base', 'version' => 'v1',
                                                'metrics' => 'transactionRevenue',
                                                'dimensions' => 'source,medium',
                                                'sort' => 'transactionRevenue-',
                                                'format' => 'json',
                                                'resultsPerPage' => 25,
                                                'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))), true);?>';

OWA.items.topsources = new OWA.resultSetExplorer('top-sources');
OWA.items.topsources.addLinkToColumn('source', '<?php echo $this->makeLink(array(
                                                                        'do' => 'base.reportSourceDetail',
                                                                        'source' => '%s'
                                                                    ),true);?>', ['source']);
OWA.items.topsources.asyncQueue.push(['refreshGrid']);
OWA.items.topsources.load(topsourcesurl);


</script>

<?php require_once('js_report_templates.php');?>

<script type="text/x-jqote-template" id="headline-template">
<![CDATA[
    There were <*= this.data.resultSet.aggregates.transactions.formatted_value *> <* if (this.data.resultSet.aggregates.transactions.value > 1) {this.label = 'transactions';} else {this.label = 'transaction';} *> <*= this.label *> generating <*= this.data.resultSet.aggregates.transactionRevenue.formatted_value *>.
]]> 
</script>

