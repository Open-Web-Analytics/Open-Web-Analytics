<h2><?=$headline;?></h2>

<fieldset id="document" class="options">
	<legend>Summary Stats</legend>
	<table>
		<TR>
			<TD colspan="10">
				<a href="<?=$detail['url'];?>"><span class="inline_h2"><?=$detail['page_title'];?></span></a><BR>
				<span class="info_text"><?=$detail['url'];?></span>
			</TD>
		
			<TD>
				<DIV CLASS="pages_box">
				<span class="large_number"><?=$summary['page_views'];?></span>
				<BR><span class="info_text">Page Views</span>
				</DIV>
			</TD>
			<TD>
				<DIV CLASS="pages_box">
				<span class="large_number"><?=$summary['sessions'];?></span>
				<BR><span class="info_text">Visits</span>
				</DIV>
			</TD>
			<TD class="">
				<DIV CLASS="pages_box">
				<span class="large_number"><?=$summary['unique_visitors'];?></span>
				<BR><span class="info_text">Unique Visitors<span>
				</DIV>
			</TD>
		</TR>
	
	</table>
	
	<? include('document_core_metrics.tpl');?>
</fieldset>

<fieldset id="top_referers" class="options">
	<legend>Top Referers</legend>
	<?=$top_referers;?>
</fieldset>


