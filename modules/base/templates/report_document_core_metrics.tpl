<?php if (empty($core_metrics)):?>
There are no metrics yet for this time period.
<?php else:?>
<table class="tablesorter">
	<thead>
	<tr>
	<?php if ($period == 'this_year' || $params['period'] == 'this_year'): ?>		
		<th>Month</th>
	<?php else: ?>
		<th>Month</th>
		<th>Day</th>
		<th>Year</th>
	<?php endif; ?>	
		<th>Page Views</th>
		<th>Visits</th>
		<th>Unique Visitors</th>

	</tr>
	</thead>
	<tbody>
	<?php foreach($core_metrics as $row): ?>	
	<TR>
	<?php if ($period == 'this_year' || $params['period'] == 'this_year'): ?>
		<TD class="item_cell"><?php echo $this->get_month_label($row['month']);?></a></TD>
	<?php else: ?>
		<TD class="item_cell"><?php echo $this->get_month_label($row['month']);?></TD>
		<TD class="item_cell"><?php echo $row['day'];?></TD>
		<TD class="item_cell"><?php echo $row['year'];?></TD>
	<? endif; ?>
		<TD class="data_cell"><?php echo $row['page_views'];?></TD>
		<TD class="data_cell"><?php echo $row['sessions'];?></TD>
		<TD class="data_cell"><?php echo $row['unique_visitors'];?></TD>
	</TR>	
	</tbody>	
   <?php endforeach; ?>
</table>
<?php endif;?>