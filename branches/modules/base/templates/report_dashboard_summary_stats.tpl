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
		<TH scope="row" class="sub-row"> New Visitors:</TH>
		<TD><?=$summary_stats['new_visitor'];?></TD>
	</TR>
	<TR>
		<TH scope="row" class="sub-row"> Repeat Visitors: </TH>
		<TD><?=$summary_stats['repeat_visitor'];?></TD>
	</TR>
	<TR>
		<TH scope="row">Unique Visitors:</TH>
		<TD><?=$summary_stats['unique_visitors'];?></TD>
	</TR>
</table>	
<?else:?>
	There are no statistics for this time period.
<?endif;?>

