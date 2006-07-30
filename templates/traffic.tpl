<style>

#summary_stats {width:500px;}
#hosts {width:200px;}
#anchors {width:300px;}
#keywords {width:300px;}
</style>

<h2><?=$headline;?></h2>

	<table width="100%">
		<TR>
			<TD colspan="2" valign="top">
				<fieldset id="summary_stats" class="options">
					<legend>Summary Stats</legend>	
					<? include('traffic_summary_stats.tpl');?>
				</fieldset>	
			</TD>
		
		
			
		</TR>
		<TR>
			<TD valign="top">
				<fieldset id="referers" class="options">
					<legend>Traffic From Web Pages</legend>	
					<? include('referers.tpl');?>
				</fieldset>	
			</TD>
		
			<TD valign="top">
			
				<fieldset id="hosts" class="options">
					<legend>Top Domains</legend>	
					<? include('hosts.tpl');?>
				</fieldset>	
						
			</TD>
			
		</TR>
		<TR>
			<TD colspan="2" valign="top">
				<fieldset id="search_engines" class="options">
					<legend>Traffic From Search Engines</legend>	
				<Table>
					<TR>
						<TD valign="top">	
							<fieldset id="se_hosts" class="options">
								<legend>Top Search Engines</legend>	
								<? include('se_hosts.tpl');?>	
							</fieldset>	
						</TD>
						
						<TD valign="top">
						
							<fieldset id="keywords" class="options">
								<legend>Top Keywords</legend>	
								<? include('keywords.tpl');?>
							</fieldset>	
							
						</TD>	
						
						<TD valign="top">
							
							<fieldset id="anchors" class="options">
								<legend>Top Link Text</legend>	
								<? include('anchors.tpl');?>
							</fieldset>	
							
						</td>
					</TR>
				</Table>
				</fieldset>
			</TD>
			
		
		</TR>
	</table>
	

