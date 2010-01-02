<div class="owa_reportSectionHeader">
There were <?php echo $summary_stats['sessions'];?> visits from referring web sites.
</div>

<div class="owa_reportSectionContent">
	<?php include('report_traffic_summary_metrics.tpl');?>
</div>
<div class="owa_reportSectionHeader">
	Top Inbound Link Text
</div>



<div class="owa_reportSectionContent">
				
<?php if (!empty($anchors)):?>
<table class="tablesorter">
	<thead>
	<tr>
		<td class="col_item_label">Link Text</td>
		<td class="col_label">Visits</td>
	</tr>
	</thead>
	<tbody>			
	<?php foreach($anchors as $anchor): ?>
		
	<TR>
		<TD class="item_cell"><?php echo $anchor['refering_anchortext'];?></td>
		<TD class="data_cell"><?php echo $anchor['count']?></TD>
	</TR>
				
	<?php endforeach; ?>
	</tbody>
</table>
<?php else:?>
	There are no refering anchors for this time period.
<?php endif;?>

<?php echo $this->makePagination($pagination, array('do' => 'base.reportAnchortext'));?>

</div>