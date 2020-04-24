<div class="owa_reportSectionContent" style="width:auto;">
<div class="owa_reportSectionHeader">Site Metrics</div>

    <div id="trend-chart" style="height:125px;"></div><BR>
    <div id="trend-metrics" style="width:auto;"></div>

</div>
<div class="clear"></div>
<table style="padding:0px;width:auto;">
    <TR>
        <TD style="width:50%" valign="top">

            <div class="owa_reportSectionContent">
                <div class="owa_reportSectionHeader">Top Content</div>

                <div id="top-pages" style="min-width:350px"></div>
                <div class="owa_moreLinks">
                    <a href="<?php echo $this->makeLink(array('do' => 'base.reportPages'), true);?>">View Full Report &raquo;</a>
                </div>
            </div>

           
            <div class="owa_reportSectionContent">
                <div class="section_header">Latest Visits</div>
                <?php include('report_latest_visits.tpl')?>
            </div>

        </TD>
        <TD style="width:50%" valign="top">

            <?php //if ($actions) { ?>
            <div class="owa_reportSectionContent" style="min-width:200px; height:;">
                <div class="section_header">Actions</div>

                <div id="actions-trend" style="width:;height:;"></div>


                

                <div class="owa_genericHorizontalList owa_moreLinks">
                    <UL>
                        <LI>
                            <a href="<?php echo $this->makeLink(array('do' => 'base.reportActionTracking'), true);?>">View Full Report &raquo;</a>
                        </LI>
                    </UL>
                </div>
                <div class="clear"></div>
            </div>
            <?php //} ?>
			<table width="100%">
				<TR>
					<TD>
			 <span class="owa_reportSectionContent">
                <div class="owa_reportSectionHeader">Visitor Types</div>
                <div id="visitor-types" class="owa_piechart"></div>
            </span>
					</TD>
					<TD>
            <span class="owa_reportSectionContent">
                <div class="owa_reportSectionHeader">Traffic Sources</div>
                <div id="visitor-mediums" class="owa_piechart"></div>
            </span>
					</TD>
				</TR>
			</table>
            <div class="owa_reportSectionContent">
                <div class="owa_reportSectionHeader">Top Referrers</div>

                <div id="top-referers" style="min-width:350px"></div>
                <div class="owa_moreLinks">
                    <a href="<?php echo $this->makeLink(array('do' => 'base.reportReferringSites'), true);?>">View Full Report &raquo;</a>
                </div>
                <div id="test"></div>
            </div>

            <div class="owa_reportSectionContent">
                <div class="section_header">OWA News</div>
                <?php echo $this->getWidget('base.widgetOwaNews','',false);?>
            </div>
        </TD>
    </TR>
</table>

<script>

    var aurl = '<?php

                    echo $this->makeApiLink(array(
                        'module'	=> 'base',
	    				'version'	=>'v1',
	    				'do' => 'reports',
                        'metrics'        => $metrics,
                        'dimensions'     => 'date',
                        'sort'             => 'date',
                        'format'         => 'json'
                    ), true);
                ?>';

    var rsh = new OWA.resultSetExplorer('site-trend');

    rsh.asyncQueue.push(['makeAreaChart', [{x: 'date', y: 'visits'}], 'trend-chart']);
    rsh.options.metricBoxes.width = '150px';
    rsh.asyncQueue.push(['makeMetricBoxes' , 'trend-metrics']);

    rsh.load(aurl);
    OWA.items['<?php echo $dom_id;?>'].registerResultSetExplorer('rsh', rsh);
	
	var burl = '<?php echo $this->makeApiLink(array('do' => 'reports', 'module' => 'base', 'version' => 'v1', 
                                                              'metrics' => 'actions', 
                                                              'dimensions' => 'actionGroup,actionName', 
                                                              'sort' => 'actions-', 
                                                              'resultsPerPage' => 5,
                                                              'format' => 'json'), true);?>';
 
	var bsh = new OWA.resultSetExplorer('actions-trend');
	bsh.options.grid.showRowNumbers = false;
	bsh.addLinkToColumn('actionGroup', '<?php echo $this->makeLink(array('do' => 'base.reportActionGroup', 'actionGroup' => '%s'), true);?>', ['actionGroup']);
	bsh.asyncQueue.push(['refreshGrid']);
	bsh.load(burl);
	OWA.items['<?php echo $dom_id;?>'].registerResultSetExplorer('bsh', bsh);
	
(function() {
    var tcurl = '<?php echo $this->makeApiLink(array('module'	=> 'base',
	    											'version'	=>'v1',
	    											'do' => 'reports',
                                                    'metrics' => 'pageViews',
                                                    'dimensions' => 'pageTitle,pageUrl',
                                                    'sort' => 'pageViews-',
                                                    'format' => 'json',
                                                    'page'    => 1,
                                                    'resultsPerPage' => 10
                                                    ),true);?>';

    OWA.items.tc = new OWA.resultSetExplorer('top-pages');
    OWA.items.tc.options.grid.showRowNumbers = false;
    OWA.items.tc.addLinkToColumn('pageTitle', '<?php echo $this->makeLink(array('do' => 'base.reportDocument', 'pageUrl' => '%s'), true);?>', ['pageUrl']);
    OWA.items.tc.options.grid.excludeColumns = ['pageUrl'];
    OWA.items.tc.asyncQueue.push(['refreshGrid']);
    OWA.items.tc.load(tcurl);
    OWA.items['<?php echo $dom_id;?>'].registerResultSetExplorer( 'tc', OWA.items.tc );
})();

(function() {
    var traurl = '<?php echo $this->makeApiLink(array('module'	=> 'base',
	    											'version'	=>'v1',
	    											'do' => 'reports',
                                                    'metrics' => 'visits',
                                                    'dimensions' => 'referralPageTitle,referralPageUrl',
                                                    'sort' => 'visits-',
                                                    'format' => 'json',
                                                    'constraints' => urlencode('medium==referral'),
                                                    'resultsPerPage' => 10
                                                    ),true);?>';


    OWA.items.topreferers = new OWA.resultSetExplorer('top-referers');
    OWA.items.topreferers.options.grid.showRowNumbers = false;
    OWA.items.topreferers.addLinkToColumn('referralPageTitle', '<?php echo $this->makeLink(array('do' => 'base.reportReferralDetail', 'referralPageUrl' => '%s'),true);?>', ['referralPageUrl']);
    OWA.items.topreferers.options.grid.excludeColumns = ['referralPageUrl'];
    OWA.items.topreferers.asyncQueue.push(['refreshGrid']);
    OWA.items.topreferers.load(traurl);
    OWA.items['<?php echo $dom_id;?>'].registerResultSetExplorer( 'topreferers', OWA.items.topreferers );

})();

(function() {
    var aturl = '<?php echo $this->makeApiLink(array(
        'module'	=> 'base',
	    'version'	=>'v1',
	    'do' => 'reports',
        'metrics' => 'actions',
        'dimensions' => 'date',
        'sort' => 'date',
        'format' => 'json',
        'period' => 'last_seven_days',
        'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))
    ));?>';

    at = new OWA.resultSetExplorer('actions-trend');
    at.options.areaChart.series.push({x:'date',y:'actions'});
    at.setView('areaChart');
    OWA.items['<?php echo $dom_id;?>'].registerResultSetExplorer( 'at', at );

    //at.load(aturl);
})();

(function() {
    var vmurl = '<?php echo $this->makeApiLink(array(
	    															'module'	=> 'base',
	    															'version'	=>'v1',
	    															'do' => 'reports',
                                                                    'metrics' => 'visits',
                                                                    'dimensions' => 'medium',
                                                                    'sort' => 'visits-',
                                                                    'format' => 'json',
                                                                    'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))),true);?>';

    var vm = new OWA.resultSetExplorer('visitor-mediums');
    vm.options.pieChart.metric = 'visits';
    vm.options.pieChart.dimension = 'medium';
    vm.setView('pie');
    vm.load(vmurl);
    OWA.items['<?php echo $dom_id;?>'].registerResultSetExplorer( 'vm', vm );
})();

(function() {
    var aurl = '<?php echo $this->makeApiLink(array('module'	=> 'base',
	    											'version'	=>'v1',
	    											'do' => 'reports',
                                                    'metrics' => 'repeatVisitors,newVisitors',
                                                    'dimensions' => '',
                                                    'sort' => 'visits',
                                                    'format' => 'json',
                                                    'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId'))),true);?>';

    OWA.items.vt = new OWA.resultSetExplorer('visitor-types');
    OWA.items.vt.options.pieChart.metrics = ['repeatVisitors', 'newVisitors'];
    OWA.items.vt.asyncQueue.push(['makePieChart']);
    OWA.items.vt.load(aurl);
    OWA.items['<?php echo $dom_id;?>'].registerResultSetExplorer( 'vt', OWA.items.vt );
})();

</script>

<?php require_once('js_report_templates.php');?>