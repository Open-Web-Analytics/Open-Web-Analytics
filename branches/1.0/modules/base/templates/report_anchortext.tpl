


<? include('report_header.tpl');?>

<P><span class="inline_h2">There were <?=$summary_stats['sessions'];?> visits from Referring Web Sites.</span></p> 

<? include('report_dashboard_summary_stats.tpl');?>
				
<? if (!empty($keywords)):?>
<table class="data_table">
	<tr>
		<td class="col_item_label">Link Text</td>
		<td class="col_label">Visits</td>
	</tr>
				
<?php foreach($anchors as $anchor): ?>
		
	<TR>
		<TD class="item_cell"><?=$anchor['refering_anchortext'];?></td>
		<TD class="data_cell"><?=$anchor['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?else:?>
	There are no refering anchors for this time period.
<?endif;?>