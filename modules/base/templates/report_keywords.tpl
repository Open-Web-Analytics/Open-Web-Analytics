<div class="owa_reportSectionHeader">There were <?=$summary_stats['sessions'];?> visits from Search Engines.</div>
<div class="owa_reportSectionContent">

<?php include('report_traffic_summary_metrics.tpl');?>

</div>

<div class="owa_reportSectionHeader">Top keywords that drove traffic</div> 
<div class="owa_reportSectionContent">
				
<?php if (!empty($keywords)):?>
<table class="data_table">
	<tr>
		<td class="col_item_label">Keyword</td>
		<td class="col_label">Visits</td>
	</tr>
				
	<?php foreach($keywords as $keyword): ?>
		
	<TR>
		<TD class="item_cell"><?=$keyword['query_terms'];?></td>
		<TD class="data_cell"><?=$keyword['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?php else:?>
	There are no Keywords for this time period.
<?php endif;?> 
</div>