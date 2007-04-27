<? if (!empty($sessions)):?>
<table>
	<TR>
		<TH>Total Visits: </TH>	
		<TH><?=$sessions['count'];?></TH>
	</TR>
	<TR>
		<TD class="indented_header_row" scope="row">from Search Engines: </TD>
		<TD><?=$from_se['se_count'];?></TD>
	</TR>
	<TR>
		<TD class="indented_header_row" scope="row">from Web Pages: </TD>
		<TD><?=$from_sites['site_count'];?></TD>
	</TR>
	<TR>
		<TD class="indented_header_row" scope="row">from Your Feeds: </TD>
		<TD><?=$from_feeds['source_count'];?></TD>
	</TR>
	<TR>
		<TD class="indented_header_row" scope="row">from Direct/Unknown: </TD>
		<TD><?=$from_direct['count'];?></TD>
	</TR>
</table>	
<?else:?>
	There are no statistics for this time period.
<?endif;?>

