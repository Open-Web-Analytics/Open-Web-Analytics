<div id="owa_header">
	<table width="100%" cellpadding="0" cellspacing="0">
		<TR>
			<TD class="owa_logo"><img src="<?=$this->makeImageLink('owa_logo_150w.jpg'); ?>" alt="Open Web Analytics"></TD>
			<TD>
				<div id="admin_nav">
				<table align="right">
					<TR>
						<TD><a href="<?=$this->makeLink(array('do' => 'base.reportDashboard'));?>">Analytics</a></TD>
						<TD>|</TD>
						<TD><a href="<?=$this->makeLink(array('view' => 'base.options'));?>">Admin Settings</a></TD>
						<TD>|</TD>
						<TD><a href="http://wiki.openwebanalytics.com">Help</a></TD>
						<TD>|</TD>
						<TD><a href="http://trac.openwebanalytics.com">Report a Bug</a></TD>
						<? if ($this->config['is_embedded'] == false):?>
						<TD>|</TD>
						<TD>
						<? if ($authStatus == true):?>
						<a href="<?=$this->makeLink(array('action' => 'base.logout'));?>">Logout</a>
						<?else:?>
						<a href="<?=$this->makeLink(array('view' => 'base.login'));?>">Login</a>
						<?endif;?>
						</TD>
						<?endif;?>
					</TR>
				</table>
				</div>
			</td>
		</TR>
	</table>
</div>