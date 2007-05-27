<style>

#visitor_summary_stats {width:400px}
#browser_types {width:250px;}

</style>

<? include ('report_header.tpl');?>

<table width="100%">
	<TR>
		<TD valign="top" id="visitor_summary_stats">
			<? include ('report_visitors_summary_stats.tpl');?>
		</TD>
		
		<TD valign="top">
			<? include ('report_top_visitors.tpl');?>
		</TD>
	</TR>
</table>

<div class="section_header inline_h2">Visitor Profile</div>


<table width="100%">
	<TR>
		<TD valign="top" id="browser_types">
			<? include ('report_browser_types.tpl');?>
		</TD>
		
		<TD valign="top">
			
		</TD>
	</TR>
</table>
