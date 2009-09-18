<div class="owa_reportSectionHeader">There were <?php echo $summary_stats['unique_visitors'];?> unique visitors of this web site.</div>
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
			<td><?php echo $domain['host'];?></td>
			<td><?php echo $domain['count']?></td>
		</TR>
				
	<?php endforeach; ?>
	</tbody>
</table>

<?php echo $this->makePagination($pagination, array('do' => $params['do']));?>

<?php else:?>
	There are no refering domains for this time period.
<?php endif;?>

</div>
