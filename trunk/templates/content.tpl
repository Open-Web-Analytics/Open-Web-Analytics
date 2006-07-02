<h2><?=$headline;?></h2>

<table width="100%">
	<TR>
		<TH>Top Entry pages</TH>
		<TH>Top Exit pages</TH>
	</TR>
	<TR>
		<TD valign="top"><?=$entry_pages;?></TD>
		<TD valign="top"><?=$exit_pages;?></TD>
	</TR>
</table>
<Table>
	<TR>
		<TH>Requests by Page Type</TH>
		<TH></TH>
	</TR>
	<TR>
		<TD>
			<img src="<?=$this->config['action_url']?>?owa_action=graph&name=page_types&type=pie&period=<?=$params['period'];?>&site_id=<?=$params['site_id'];?>" />		
		</TD>
		<TD>
		
		</TD>
	</TR>
</Table>
