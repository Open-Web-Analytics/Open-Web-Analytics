<div class="owa_reportSectionHeader">There were <?=$summary_stats['unique_visitors'];?> unique visitors of this web site.</div>
<div class="owa_reportSectionContent">
<?php include('report_dashboard_summary_stats.tpl');?>
</div>

<div class="owa_reportSectionHeader">Top Domain</div>
<div class="owa_reportSectionContent">

<?php if (!empty($domains)):?>
<table class="tablesorter">
	<thead>
		<tr>
			<th>Domain</th>
			<th>Visits</th>
		</tr>
	</thead>
	<tbody>			
	<?php foreach($domains as $domain): ?>
		
		<TR>
			<td><?=$domain['host'];?></td>
			<td><?=$domain['count']?></td>
		</TR>
				
	<?php endforeach; ?>
	</tbody>
</table>
	
<?=$this->makePagination($pagination, $params['do']);?>

<?php else:?>
	There are no refering domains for this time period.
<?php endif;?>

</div>
