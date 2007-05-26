
<? include('report_header.tpl');?>

<P><span class="inline_h2">There were <?=$summary_stats['sessions'];?> visits from Search Engines.</span></p> 

<? include('report_dashboard_summary_stats.tpl');?>
				
<? if (!empty($keywords)):?>
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
<?else:?>
	There are no Keywords for this time period.
<?endif;?> 