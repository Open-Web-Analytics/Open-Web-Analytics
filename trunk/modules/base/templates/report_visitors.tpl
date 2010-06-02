



<div class="owa_reportSectionHeader">
There were <?php echo $summary_stats['unique_visitors'];?> Visitors to this Web site.
</div>
<div class="owa_reportSectionContent">
	<div class="owa_reportSectionHeader">Visitor Trend</div>
	<div id="visitor-trend" style="height:125px;"></div>
	<script>
	//OWA.setSetting('debug', true);
	var aurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
													'metrics' => 'uniqueVisitors', 
													'dimensions' => 'date', 
													'sort' => 'date',
													'format' => 'json'), true);?>';
													  
	rsh = new OWA.resultSetExplorer('visitor-trend');
	rsh.options.areaChart.series.push({x:'date',y:'uniqueVisitors'});
	rsh.setView('areaChart');
	rsh.load(aurl);
	
	</script>
	
</div>

<table>
	<TR>
		<TD valign="top">
		
			<table width="100%">
				<TR>
					<TD width="50%" valign="top">
						<div class="owa_reportSectionContent">
						<?php include ('report_visitors_summary_stats.tpl');?>	
						</div>	
					</TD>
					<TD width="50%" valign="top">
						<div class="owa_reportSectionContent">
							<div class="section_header inline_h2">Visitor Reports</div>
							<P>
								<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.reportVisitorsLoyalty'));?>">Visitor Loyalty</a></span> - See how long ago your visitors first came to your web site.
							</P>
							<P>
								<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.reportVisitsGeolocation'));?>">Geo-location</a></span> - See which parts of the world your visitors are coming from.
							</P>
							<P>
								<span class="inline_h3"><a href="<?php echo $this->makeLink(array('do' => 'base.reportHosts'));?>">Domains</a></span> - See which Networks or Internet hosts your visitors are coming from.
							</P>
						</div>
					</TD>
				</TR>
			</table>
			<BR>
		
			
			<div class="owa_reportSectionContent">	
			<div class="owa_reportSectionHeader">Latest Visits</div>
			<?php include('report_latest_visits.tpl')?>
			<?php echo $this->makePagination($pagination, array('do' => $params['do']));?>
			</div>
		</TD>
		<TD valign="top">
			
			<div class="owa_reportSectionContent">
			<div class="owa_reportSectionHeader">Browser Types</div>
			<?php include ('report_browser_types.tpl');?>
			</div><BR>
			<div class="owa_reportSectionContent">
			<div class="owa_reportSectionHeader">Most Frequent Visitors</div>
			<?php include ('report_top_visitors.tpl');?>	
			</div>
		</TD>
	</TR>
</table>


</div>