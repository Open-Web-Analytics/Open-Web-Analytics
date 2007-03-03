
	<table width="100%" cellpadding="0" cellspacing="0">
		<TR>
			<TD class=""><h1>Open Web Analytics</h1></TD>
			<TD>
				<div id="admin_nav">
				<table align="right">
					<TR>
						<TD><a href="<?=$this->makeLink(array('do' => 'base.reportDashboard'));?>">Web Analytics</a></TD>
						<TD>|</TD>
						<TD><a href="<?=$this->makeLink(array('view' => 'base.options'));?>">Admin Options</a></TD>
						<TD>|</TD>
						<TD><a href="http://wiki.openwebanalytics.com">Help</a></TD>
						<TD>|</TD>
						<TD><a href="http://trac.openwebanalytics.com">Bug Report</a></TD>
						<? if ($this->config['is_embedded'] != true):?>
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
