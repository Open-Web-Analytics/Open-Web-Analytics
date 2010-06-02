<div class="owa_reportSectionHeader">There were <?php echo $summary_stats['sessions'];?> visits from Search Engines.</div>
<div class="owa_reportSectionContent">



</div>


<div class="owa_reportSectionContent">
<div class="owa_reportSectionHeader">Top keywords that drove traffic</div> 				
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
		<td><?php echo $keyword['query_terms'];?></td>
		<TD><?php echo $keyword['count']?></TD>
	</TR>
				
	<?php endforeach; ?>
	</tbody>
</table>
<?php else:?>
	There are no Keywords for this time period.
<?php endif;?> 

<?php echo $this->makePagination($pagination, array('do' => 'base.reportKeywords'));?>
</div>