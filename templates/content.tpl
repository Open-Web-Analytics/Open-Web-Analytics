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
			<img src="<?=$this->makeGraphLink('page_types');?>">
		</TD>
		<TD>
		
		</TD>
	</TR>
</Table>
