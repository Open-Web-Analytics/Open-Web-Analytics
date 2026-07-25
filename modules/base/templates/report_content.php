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
                <div class="owa_reportSectionHeader">Top Pages</div>

                <div id="top-pages"></div>
                <div class="owa_genericHorizonalList owa_moreLinks">
                    <UL>
                        <LI>
                            <a href="<?php echo $this->makeLink(array('do' => 'base.reportPages'), true);?>">View Full Report &raquo;</a>
                        </LI>
                    </UL>
                </div>
            </div>

        </td>

        <td valign="top" style="width:50%;">
            <div class="owa_reportSectionHeader">Content Reports</div>
                <div class="relatedReports">
                    <UL>
                        <LI>
                            <a href="<?php echo $this->makeLink(array('do' => 'base.reportDomstreams'), true);?>">Domstream Recordings</a></span> - See user mouse movement and keypress recordings.
                        </LI>
                        <LI>
                            <a href="<?php echo $this->makeLink(array('do' => 'base.reportActionTracking'), true);?>">Actions</a></span> - See which actions your user performed.
                        </LI>
                        <LI>
                            <a href="<?php echo $this->makeLink(array('do' => 'base.reportReferringSites'), true);?>">Entry & Exits</a></span> - See which web pages user entered and exited on.
                        </LI>
                        <LI>
                            <a href="<?php echo $this->makeLink(array('do' => 'base.reportAnchortext'), true);?>">Feeds</a></span> - See trends for feed subscribers and usage.
                        </LI>
                    </UL>
                </div>
            </div>

            <div class="owa_reportSectionContent" style="min-width:350px;">
                <div class="owa_reportSectionHeader">Top Page Types</div>
                <div id="top-pagetypes" style="width:auto;margin-top:-10px;"></div>
                <div class="owa_genericHorizonalList owa_moreLinks">
                    <UL>
                        <LI>
                            <a href="<?php echo $this->makeLink(array('do' => 'base.reportPageTypes'), true);?>">View Full Report &raquo;</a>
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
                                                'metrics' => 'visits,pageViews,bounceRate',
                                                'dimensions' => 'date',
                                                'sort' => 'date',
                                                'format' => 'json',
                                                'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))), true);?>';

OWA.items.rsh = new OWA.resultSetExplorer('trend-chart');
OWA.items.rsh.options.metricBoxes.width = '125px';
OWA.items.rsh.asyncQueue.push(['makeAreaChart', [{x:'date',y:'pageViews'}]]);
OWA.items.rsh.asyncQueue.push(['makeMetricBoxes', 'trend-metrics']);
OWA.items.rsh.asyncQueue.push(['renderTemplate','#content-headline-template', {data: OWA.items.rsh}, 'replace', 'content-headline']);
OWA.items.rsh.load(aurl);

var toppagesurl = '<?php echo $this->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1', 
                                                'metrics' => 'visits',
                                                'dimensions' => 'pageTitle,pageUrl',
                                                'sort' => 'visits-',
                                                'format' => 'json',
                                                'resultsPerPage' => 25,
                                                'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))), true);?>';

OWA.items.toppages = new OWA.resultSetExplorer('top-pages');
OWA.items.toppages.addLinkToColumn('pageTitle', '<?php echo $this->makeLink(array('do' => 'base.reportDocument', 'pageUrl' => '%s'),true);?>', ['pageUrl']);
OWA.items.toppages.options.grid.excludeColumns = ['pageUrl'];
OWA.items.toppages.asyncQueue.push(['refreshGrid']);
OWA.items.toppages.load(toppagesurl);

var toppagetypesurl = '<?php echo $this->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1', 
                                                'metrics' => 'visits',
                                                'dimensions' => 'pageType',
                                                'sort' => 'visits-',
                                                'format' => 'json',
                                                'resultsPerPage' => 25,
                                                'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))), true);?>';

OWA.items.toppagetypes = new OWA.resultSetExplorer('top-pagetypes');
OWA.items.toppagetypes.asyncQueue.push(['refreshGrid']);
OWA.items.toppagetypes.addLinkToColumn('pageType', '<?php echo $this->makeLink(array('do' => 'base.reportPageTypeDetail', 'pageType' => '%s'),true);?>', ['pageType']);
OWA.items.toppagetypes.load(toppagetypesurl);


</script>

<?php require_once('js_report_templates.php');?>

<script type="text/x-jqote-template" id="content-headline-template">
<![CDATA[
    There were <*= this.data.resultSet.aggregates.pageViews.value *> <* if (this.data.resultSet.aggregates.pageViews.value > 1) {this.label = 'page views';} else {this.label = 'page view';} *> <*= this.label *> of all pages.
]]> 
</script>

