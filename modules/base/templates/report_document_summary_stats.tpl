<table id="summary_stats">
	<TR>
		<TD><span class="inline_h2"><?=$detail['page_title'];?></span> (<?=$detail['page_type'];?>)<BR>
		<a href="<?=$detail['url'];?>"><?=$detail['url'];?></a>
		</TD>
		
		<TD>
			Page Views<BR>
			<span class="large_number"><?=$summary_stats['page_views'];?></span>
		</TD>
	
		<TD>
			Visits<BR>
			<span class="large_number"><?=$summary_stats['sessions'];?></span>
		</TD>

		<TD>
			Unique Visitors<BR>
			<span class="large_number"><?=$summary_stats['unique_visitors'];?></span>
		</TD>
	</TR>
</table>
