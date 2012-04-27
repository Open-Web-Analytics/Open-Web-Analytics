<div class="owa_reportSectionContent">
	<div id="visitor-trend" style="height:125px;width:auto;"></div>
	<div id="trend-metrics"></div>
	
	<script>
	//OWA.setSetting('debug', true);
	var aurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
													'metrics' => 'uniqueVisitors,newVisitors,repeatVisitors,visits,visitDuration', 
													'dimensions' => 'date', 
													'sort' => 'date',
													'format' => 'json'), true);?>';
													  
	OWA.items.visitortrend = new OWA.resultSetExplorer('visitor-trend');
	OWA.items.visitortrend.asyncQueue.push(['makeAreaChart', [{x:'date',y:'uniqueVisitors'}], 'visitor-trend']);
	OWA.items.visitortrend.asyncQueue.push(['makeMetricBoxes' , 'trend-metrics']);
	OWA.items.visitortrend.asyncQueue.push(['renderTemplate','#visitors-headline-template', {data: OWA.items.visitortrend}, 'replace', 'visitors-headline']);
	OWA.items.visitortrend.options.metricBoxes.width = '135px';
	OWA.items.visitortrend.load(aurl);
	
	</script>
	<div class="clear"></div>
	<div class="owa_reportHeadline" id="visitors-headline"></div>
	
</div>

		
	<table width="100%">
		<TR>
			<td width="50%" valign="top">
				<div class="owa_reportSectionContent" style="">	
					<div class="owa_reportSectionHeader">Latest Visits</div>
					<?php include('report_latest_visits.tpl')?>
					<?php echo $this->makePaginationFromResultSet($visits, array('do' => 'base.reportVisitors'), true);?>
				</div>
			</td>
			<TD width="50%" valign="top">
				<div class="owa_reportSectionContent">
					<div class="section_header inline_h2">Visitor Reports</div>
					<P>
						<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.reportVisitorsLoyalty'), true);?>">Visitor Loyalty</a></span> - See how long ago your visitors first came to your web site.
					</P>
					<P>
						<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.reportGeolocation'), true);?>">Geo-location</a></span> - See which parts of the world your visitors are coming from.
					</P>
					<P>
						<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.reportHosts'), true);?>">Domains</a></span> - See which Networks or Internet hosts your visitors are coming from.
					</P>
				</div>
				
		
				<div class="owa_reportSectionContent">
					<div class="owa_reportSectionHeader">Browser Types</div>
					<div id="top-browsers"></div>
					<script>
					
						var bturl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																				'metrics' => 'visits', 
																				'dimensions' => 'browserType', 
																				'sort' => 'visits-',
																				'resultsPerPage' => 10,
																				'format' => 'json'
																				),true);?>';
																				  
						OWA.items.browsertypes = new OWA.resultSetExplorer('top-browsers');
						OWA.items.browsertypes.addLinkToColumn('browserType', '<?php echo $this->makeLink(array('do' => 'base.reportBrowserDetail', 'browserType' => '%s'),true); ?>', ['browserType']);
						OWA.items.browsertypes.asyncQueue.push(['refreshGrid']);
						OWA.items.browsertypes.load(bturl);
						
					</script>	
				</div>
				
				<div class="owa_reportSectionContent">
					<div class="owa_reportSectionHeader">Most Frequent Visitors</div>
					<div id="top-visitors"></div>
					<script>
					
						var tvurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
																				'metrics' => 'visits,pageViews', 
																				'dimensions' => 'visitorId', 
																				'sort' => 'visits-',
																				'resultsPerPage' => 10,
																				'format' => 'json'
																				),true);?>';
																				  
						OWA.items.topvisitors = new OWA.resultSetExplorer('top-visitors');
						OWA.items.topvisitors.addLinkToColumn('visitorId', '<?php echo $this->makeLink(array('do' => 'base.reportVisitor', 'visitorId' => '%s')); ?>', ['visitorId']);
						OWA.items.topvisitors.asyncQueue.push(['refreshGrid']);
						OWA.items.topvisitors.load(tvurl);
						
					</script>	
				</div>
				
			</TD>
		</TR>
	</table>
	
<?php require_once('js_report_templates.php');?>

<script type="text/x-jqote-template" id="visitors-headline-template">
<![CDATA[
	There were <*= this.data.resultSet.aggregates.uniqueVisitors.formatted_value *> <* if (this.data.resultSet.aggregates.uniqueVisitors.value > 1) {this.label = 'visitors';} else {this.label = 'visitor';} *> <*= this.label *> to this web site.
]]> 
</script>