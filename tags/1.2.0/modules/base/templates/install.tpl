<div style="width:800px; margin: 0px auto -1px auto;">
	<div class="inline_h1" style="text-align:center;"><?=$headline;?></div>
	
	<div style="width:100%; margin: 0px auto -1px auto; text-align:center;">
	<BR>
	<table style="border:1px solid #efefef; width:100%;">
		<TR>
			<TD colspan="2">
				<P>
					Steps: &nbsp;
					<span class="<? if ($step == 'base.installStart'):?>active_wizard_step<?else:?>wizard_step<?endif;?>">Welcome</span> &nbsp; > &nbsp;
					<span class="<? if ($step == 'base.installCheckEnv'):?>active_wizard_step<?else:?>wizard_step<?endif;?>">Environment Check</span> &nbsp; > &nbsp;
					<span class="<? if ($step == 'base.installDefaultSiteProfileEntry'):?>active_wizard_step<?else:?>wizard_step<?endif;?>">Default Site Profile</span> &nbsp; > &nbsp;
					<span class="<? if ($step == 'base.installAdminUserEntry'):?>active_wizard_step<?else:?>wizard_step<?endif;?>">Admin User</span> &nbsp; > &nbsp;
					<span class="<? if ($step == 'base.installFinish'):?>active_wizard_step<?else:?>wizard_step<?endif;?>">Finish</span>	
				</P>
			</TD>
		</TR>
	</table>
	<BR>
	
	<div class="layout_subview" valign="top" style="text-align:left;"><?=$subview;?></div>

</div>
