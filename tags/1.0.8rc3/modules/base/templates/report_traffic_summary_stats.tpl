<? if (!empty($sessions)):?>
<table id="summary_stats">
	<TR>
		<TD>
			<span class="large_number"><?=$sessions['count'];?></span> Total Visits
		</TD>
	</TR>
	<TR>
		<TD>
			<span class="large_number"><?=$from_se['se_count'];?></span> Visits from Search Engines
		</TD>
	</TR>
	<TR>
		<TD>
			<span class="large_number"><?=$from_sites['site_count'];?></span> Visits from Referring Web Pages
		</TD>
	</TR>
	<TR>
		<TD>
			<span class="large_number"><?=$from_feeds['source_count'];?></span> Visits from Feeds
		</TD>
	</TR>
	<TR>
		<TD>
			<span class="large_number"><?=$from_direct['count'];?></span> Direct Visits
		</TD>
	</TR>
</table>	
<?else:?>
	There are no statistics for this time period.
<?endif;?>