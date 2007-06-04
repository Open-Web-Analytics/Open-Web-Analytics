<? if (!empty($summary_stats)):?>
<table id="summary_stats" cellpadding="0" cellspacing="0" width="100%">
	<TR>	
		<TD>
			Visits<BR>
			<span class="large_number"><?=$summary_stats['sessions'];?></span>
		</TD>
		
		<TD>
			Pages/Visit<BR>
			<span class="large_number">
				<? if ($summary_stats['sessions'] != 0):?>
					<?=round($summary_stats['page_views']/$summary_stats['sessions'],2);?>
				<?else:?>
					0
				<?endif;?>
			</span>
		</TD>
	
		<TD>
			Unique Visitors<BR>
			<span class="large_number"><?=$summary_stats['unique_visitors'];?></span>
		</TD>
		
		<TD>
			New Visitors<BR>
			<span class="large_number"><?=$summary_stats['new_visitor'];?></span>
		</TD>
	
		<TD>
			Repeat Visitors<BR>
			<span class="large_number"><?=$summary_stats['repeat_visitor'];?></span>
		</TD>
	</TR>
</table>	
<?else:?>
	There are no statistics for this time period.
<?endif;?>

