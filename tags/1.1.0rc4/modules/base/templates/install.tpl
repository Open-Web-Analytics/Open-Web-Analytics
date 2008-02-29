<Table id="layout_panels" cellpadding="0" cellspacing="0">
	<TR>
		<TD colspan="2" class="headline">
			<?=$headline;?>
		</TD>
	</TR>
	<TR>
		<TD colspan="2">
			<P>
				Install Steps: &nbsp;
				<span class="<? if ($step == 'base.installStart'):?>active_wizard_step<?else:?>wizard_step<?endif;?>">Welcome</span> &nbsp; >> &nbsp;
				<span class="<? if ($step == 'base.installCheckEnv'):?>active_wizard_step<?else:?>wizard_step<?endif;?>">Server Environment Check</span> &nbsp; >> &nbsp;
				<span class="<? if ($step == 'base.installDefaultSiteProfile'):?>active_wizard_step<?else:?>wizard_step<?endif;?>">Default Site Profile</span> &nbsp; >> &nbsp;
				<span class="<? if ($step == 'base.installAdminUser'):?>active_wizard_step<?else:?>wizard_step<?endif;?>">Admin User</span> &nbsp; >> &nbsp;
				<span class="<? if ($step == 'base.installFinish'):?>active_wizard_step<?else:?>wizard_step<?endif;?>">Finish</span>	
			</P>
		</TD>
	</TR>

	<TR>
		<TD class="layout_subview" valign="top"><?=$subview;?></TD>
	</TR>

</Table>
