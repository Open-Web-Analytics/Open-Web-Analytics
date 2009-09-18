



<div class="owa_reportSectionHeader">
There were <?php echo $summary_stats['unique_visitors'];?> Visitors to this Web site.
</div>

<div class="owa_reportSectionContent">



<table>
	<TR>
		<TD valign="top">
		
			<table width="100%">
				<TR>
					<TD width="50%" valign="top">
						<?php include ('report_visitors_summary_stats.tpl');?>		
					</TD>
					<TD width="50%" valign="top">
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
					</TD>
				</TR>
			</table>
			<BR>
		
			<div class="owa_reportSectionHeader">Latest Visits</div>
			<div class="owa_reportSectionContent">	
			<?php include('report_latest_visits.tpl')?>
			<?php echo $this->makePagination($pagination, array('do' => $params['do']));?>
			</div>
		</TD>
		<TD valign="top">
			<div class="owa_reportSectionHeader">Browser Types</div>
			<div class="owa_reportSectionContent">
			<?php include ('report_browser_types.tpl');?>
			</div><BR>
			<div class="owa_reportSectionHeader">Most Frequent Visitors</div>
			<div class="owa_reportSectionContent">
			<?php include ('report_top_visitors.tpl');?>	
			</div>
		</TD>
	</TR>
</table>


</div>