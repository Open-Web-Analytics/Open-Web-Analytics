<style>
	#summary_stats {width:370px;}
	#hosts {width:px;}
	#anchors {width:px;}
	#keywords {width:px;}
</style>

<h2><?=$headline;?>: <?=$period_label;?><?=$date_label;?></h2>

<table width="100%">
	<TR>
		<TD valign="top" width:"370">
			<fieldset id="summary_stats">
				<legend>Summary</legend>	
				<? include('report_traffic_summary_stats.tpl');?>
			</fieldset>	
			
			<fieldset id="anchors">
				<legend>Top Link Text</legend>	
				<? include('report_anchors.tpl');?>
			</fieldset>	
			
			<fieldset id="hosts">
				<legend>Top Domains</legend>	
				<? include('report_hosts.tpl');?>
			</fieldset>	
							
		</TD>	
	
		<TD valign="top">
		
			<fieldset id="referers">
				<legend>Traffic From Web Pages</legend>	
				<? include('report_referers.tpl');?>
			</fieldset>	
			
			<fieldset id="search_engines">
				<legend>Traffic From Search Engines</legend>	
				<Table>
					<TR>
						<TD valign="top">	
							<fieldset id="se_hosts">
								<legend class="sub-legend">Top Search Engines</legend>	
								<? include('report_se_hosts.tpl');?>	
							</fieldset>	
						</TD>
						
						<TD valign="top">
						
							<fieldset id="keywords">
								<legend class="sub-legend">Top Keywords</legend>	
								<? include('report_top_keywords.tpl');?>
							</fieldset>	
							
						</TD>	
						
					</TR>
				</Table>
			</fieldset>
			
		</TD>
	</TR>
</table>
	
 