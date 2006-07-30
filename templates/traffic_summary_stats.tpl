<? if (!empty($data)):?>
<table>
	<TR>
		<TH scope="row">Total Visits:</TH>	
		<TD><?=$visits['visits'];?></TD>
	</TR>
	<TR>
		<TH scope="row">Visits from Search Engines:</TH>
		<TD><?=$from_se['count'];?></TD>
	</TR>
	<TR>
		<TH scope="row">Visits from Web Pages</TH>
		<TD><?=$from_pages['count'];?></TD>
	</TR>
	<TR>
		<TH scope="row">Visits from Your Feeds:</TH>
		<TD><?=$from_feed['count'];?></TD>
	</TR>
	<TR>
		<TH scope="row">Visits from Direct/Unknown: </TH>
		<TD><?=$from_direct['count'];?></TD>
	</TR>
</table>	
<?else:?>
	There are no statistics for this time period.
<?endif;?>

