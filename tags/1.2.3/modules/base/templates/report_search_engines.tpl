<div class="owa_reportSectionHeader">
	There were <?php echo $summary_stats['sessions'];?> visits from Search Engines.
</div>

<div class="owa_reportSectionContent">
<?php include('report_traffic_summary_metrics.tpl');?>	
</div>


<div class="owa_reportSectionHeader">
	Top Search Engines
</div>

<div class="owa_reportSectionContent">			
	<?php if (!empty($se_hosts)):?>
	
	<table class="tablesorter">
		<thead>
			<tr>
				<th>Search Engine</th>
				<th>Visits</th>
			</tr>
		</thead>
		<tbody>			
		<?php foreach($se_hosts as $host): ?>
			
		<TR>
			<td><?php if ($host['site_name']): ?>
					<?php echo $host['site_name'];?> (<?php echo $host['site'];?>) 
				<?php  else:?>
					<?php echo $host['site'];?>
				<?php endif;?>
			</td>
			<TD class="data_cell"><?php echo $host['count']?></TD>
		</TR>
					
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php else:?>
		There are no refering search engines for this time period.
	<?php endif;?>


<?php echo $this->makePagination($pagination, array('do' => 'base.reportSearchEngines'));?>
</div>
