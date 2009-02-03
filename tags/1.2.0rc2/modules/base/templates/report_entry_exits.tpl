

<div class="owa_reportSectionHeader">There were <?=$summary_stats['page_views'];?> page views for this web site.</div> 

<div class"owa_reportSectionContent">
<? include('report_dashboard_summary_stats.tpl');?>
</div>

<div class="owa_reportSectionHeader">Top Entry & Exist Pages</div> 

<div class"owa_reportSectionContent">

<table class="layout_container" width="100%" cellpadding="0" cellspacing="0">
	<TR>
		<TD valign="top">			
			<? include('report_top_entry_pages.tpl');?>
		</TD>
			
		<TD valign="top">
			<? include('report_top_exit_pages.tpl');?>
		</TD>
	</TR>
</table>	

</div>