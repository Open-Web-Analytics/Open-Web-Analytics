<h2><?=$headline;?></h2>

	<table width="100%">
		<TR>
			<TD valign="top">
				<fieldset id="keywords" class="options">
					<legend>Top Keywords</legend>	
					<? include('keywords.tpl');?>
				</fieldset>			
			</TD>
			<TD valign="top">
				<fieldset id="anchors" class="options">
					<legend>Top Anchor Text</legend>	
					<? include('anchors.tpl');?>
				</fieldset>	
			</TD>
		</TR>
	</table>
	

