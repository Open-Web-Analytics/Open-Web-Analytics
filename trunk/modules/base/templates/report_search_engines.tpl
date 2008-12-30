<div class="owa_reportSectionHeader">
	There were <?=$summary_stats['sessions'];?> visits from Search Engines.
</div>

<div class="owa_reportSectionContent">
<?php include('report_traffic_summary_metrics.tpl');?>	
</div>


<div class="owa_reportSectionHeader">
	Top Search Engines
</div>

<div class="owa_reportSectionContent">			
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
	<?php else:?>
		There are no refering search engines for this time period.
	<?php endif;?>
</div>