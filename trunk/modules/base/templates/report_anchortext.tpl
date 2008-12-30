<div class="owa_reportSectionHeader">
There were <?=$summary_stats['sessions'];?> visits to this web site.
</div>

<div class="owa_reportSectionContent">
	<?php include('report_traffic_summary_metrics.tpl');?>
</div>
<div class="owa_reportSectionHeader">
	Top Inbound Link Text
</div>



<div class="owa_reportSectionContent">
				
<?php if (!empty($anchors)):?>
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
<?php else:?>
	There are no refering anchors for this time period.
<?php endif;?>

</div>