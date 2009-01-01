



<div class="owa_reportSectionHeader">
There were <?=$summary_stats['unique_visitors'];?> Visitors to this Web site.
</div>

<div class="owa_reportSectionContent">

<table width="100%">
	<TR>
		<TD width="33%" valign="top">
			<? include ('report_visitors_summary_stats.tpl');?>		
		</TD>
		<TD width="33%" valign="top">
		<? include ('report_browser_types.tpl');?>
		</TD>
		<TD width="33%" valign="top">
			<div class="section_header inline_h2">Visitor Reports</div>
			<P>
				<span class="inline_h3"><a href="<?=$this->makeLink(array('do' => 'base.reportVisitorsLoyalty'));?>">Visitor Loyalty</a></span> - See how long ago your visitors first came to your web site.
			</P>
			<P>
				<span class="inline_h3"><a href="<?=$this->makeLink(array('do' => 'base.reportVisitsGeolocation'));?>">Geo-location</a></span> - See which parts of the world your visitors are coming from.
			</P>
			<P>
				<span class="inline_h3"><a href="<?=$this->makeLink(array('do' => 'base.reportHosts'));?>">Domains</a></span> - See which Networks or Internet hosts your visitors are coming from.
			</P>
			
		</TD>

	</TR>

</table>




</div>

<div class="owa_reportSectionHeader">Most Frequent Visitors</div>
<div class="owa_reportSectionContent">
<? include ('report_top_visitors.tpl');?>	
</div>
	