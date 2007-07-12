<? include('report_header.tpl');?>

<P><span class="inline_h2">There were <?=$summary_stats['page_views'];?> page views for this web site.</span></p> 

<? include('report_dashboard_summary_stats.tpl');?>

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
