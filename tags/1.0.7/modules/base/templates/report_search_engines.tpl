
<? include('report_header.tpl');?>

<P><span class="inline_h2">There were <?=$summary_stats['sessions'];?> visits from Search Engines.</span></p> 

<? include('report_dashboard_summary_stats.tpl');?>
				
<? if (!empty($se_hosts)):?>

<table class="data_table">
	<tr>
		<td class="col_item_label">Search Engine</td>
		<td class="col_label">Visits</td>
	</tr>
				
	<?php foreach($se_hosts as $host): ?>
		
	<TR>
		<TD class="item_cell"><? if ($host['site_name']): ?>
				<?=$host['site_name'];?> (<?=$host['site'];?>) 
			<? else:?>
				<?=$host['site'];?>
			<?endif;?>
		</td>
		<TD class="data_cell"><?=$host['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?else:?>
	There are no refering search engines for this time period.
<?endif;?>