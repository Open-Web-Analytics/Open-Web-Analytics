<Table id="layout_panels" cellpadding="0" cellspacing="0">
	<TR>
		<TD colspan="2" class="headline">
			<?=$headline;?>
		</TD>
	</TR>
	<TR>
		<TD colspan="2">
		<P>The OWA installation wizard will guide you through the processing of settig up OWA on your server. You will need to have access to your email to complete the installation.</P>
		</TD>
	</TR>

	<TR>
		<TD valign="top" id="nav_left">
			
			<H4>Install Steps</H4>
			
			<OL>
				<LI class="<? if ($step == 'base.installStart'):?>active_tab<?endif;?>">Welcome</LI>
				<LI class="<? if ($step == 'base.installCheckEnv'):?>active_tab<?endif;?>">Server Environment Check</LI>
				<LI class="<? if ($step == 'base.installDefaultSiteProfile'):?>active_tab<?endif;?>">Site Profile Setup</LI>
				<LI class="<? if ($step == 'base.installAdminUser'):?>active_tab<?endif;?>">Admin User Setup</LI>
				<LI class="<? if ($step == 'base.installFinish'):?>active_tab<?endif;?>">Install Complete</LI>
				<LI class="<? if ($step == 'base.installPackages'):?>active_tab<?endif;?>">Optional Packages</LI>
					
			</UL>

		</TD>
		<TD class="layout_subview" valign="top"><?=$subview;?></TD>
	</TR>

</Table>
