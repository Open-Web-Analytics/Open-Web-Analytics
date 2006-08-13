<? if (!empty($sessions)):?>
<table>
	<TR>
		<TH scope="row">Total Visits:</TH>	
		<TD class="large_number"><?=$sessions['count'];?></TD>
	</TR>
	<TR>
		<TH class="indented_header_row" scope="row">Visits from Search Engines:</TH>
		<TD><?=$from_se['se_count'];?></TD>
	</TR>
	<TR>
		<TH class="indented_header_row" scope="row">Visits from Web Pages</TH>
		<TD><?=$from_sites['site_count'];?></TD>
	</TR>
	<TR>
		<TH class="indented_header_row" scope="row">Visits from Your Feeds:</TH>
		<TD><?=$from_feeds['source_count'];?></TD>
	</TR>
	<TR>
		<TH class="indented_header_row" scope="row">Visits from Direct/Unknown: </TH>
		<TD><?=$from_direct['count'];?></TD>
	</TR>
</table>	
<?else:?>
	There are no statistics for this time period.
<?endif;?>

