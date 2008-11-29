<div class="owa_reportSectionHeader">There were <?=$summary_stats['unique_visitors'];?> unique visitors of this web site.</div>

<?php include('report_dashboard_summary_stats.tpl');?>


<?php if (!empty($domains)):?>
<table class="data_table">
	<tr>
		<td class="col_item_label">Domain</td>
		<td class="col_label">Visits</td>
	</tr>
				
	<?php foreach($domains as $domain): ?>
		
	<TR>
		<TD class="item_cell"><?=$domain['host'];?></td>
		<TD class="data_cell"><?=$domain['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
	
	<?=$this->makePagination($pagination, $params['do']);?>
<?php else:?>
	There are no refering domains for this time period.
<?php endif;?>