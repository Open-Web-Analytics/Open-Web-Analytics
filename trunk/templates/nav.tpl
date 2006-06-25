<div id="admin_nav">
<table align="right">
	<TR>
		<TD><a href="<?=$this->config['public_url'];?>/admin/options.php">Options</a></TD>
		<TD>|</TD>
		<TD><a href="http://wiki.openwebanalytics.com">Help</a></TD>
		<TD>|</TD>
		<TD>Bug Report</TD>
	</TR>
</table>
</div>

<div id="top_level_nav">
<table>
	<TR>
		<TD class="top_level_nav_link"><a href="<?=$this->make_report_link('dashboard_report.php', array('site_id' => $params['site_id'], 'period' => $params['period']));?>">Dashboard</a></TD>
		<TD class="top_level_nav_link">|</TD>
		<TD class="top_level_nav_link">Visitors</TD>
		<TD class="top_level_nav_link">|</TD>
		<TD class="top_level_nav_link">Traffic Sources</TD>
		<TD class="top_level_nav_link">|</TD>
		<TD class="top_level_nav_link"><a href="<?=$this->make_report_link('content_report.php', array('site_id' => $params['site_id'], 'period' => $params['period']));?>">Content</a></TD>
		<TD class="top_level_nav_link">|</TD>
		<TD class="top_level_nav_link"><a href="<?=$this->make_report_link('feeds_report.php', array('site_id' => $params['site_id'], 'period' => $params['period']));?>">Feeds</TD>
	</TR>
</table>
</div>
