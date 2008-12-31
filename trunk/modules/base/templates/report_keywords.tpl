<div class="owa_reportSectionHeader">There were <?=$summary_stats['sessions'];?> visits from Search Engines.</div>
<div class="owa_reportSectionContent">

<?php include('report_traffic_summary_metrics.tpl');?>

</div>

<div class="owa_reportSectionHeader">Top keywords that drove traffic</div> 
<div class="owa_reportSectionContent">
				
<?php if (!empty($keywords)):?>
<table class="tablesorter">
	<thead>
		<tr>
			<th>Keyword</th>
			<th>Visits</th>
		</tr>
	</thead>
	<tbody>			
	<?php foreach($keywords as $keyword): ?>
		
	<TR>
		<td><?=$keyword['query_terms'];?></td>
		<TD><?=$keyword['count']?></TD>
	</TR>
				
	<?php endforeach; ?>
	</tbody>
</table>
<?php else:?>
	There are no Keywords for this time period.
<?php endif;?> 

<?=$this->makePagination($pagination, array('do' => 'base.reportKeywords'));?>
</div>