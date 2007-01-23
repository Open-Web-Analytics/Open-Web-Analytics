<? if (!empty($summary_stats)):?>
<table>
	<TR>
		<TH scope="row">Page Views:</TH>	
		<TD><?=$summary_stats['page_views'];?></TD>
	</TR>
	<TR>
		<TH scope="row">Visits:</TH>
		<TD><?=$summary_stats['sessions'];?></TD>
	</TR>
	<TR>
		<TH scope="row">Unique Visitors:</TH>
		<TD><?=$summary_stats['unique_visitors'];?></TD>
	</TR>
</table>	
<?else:?>
	There are no statistics for this time period.
<?endif;?>

