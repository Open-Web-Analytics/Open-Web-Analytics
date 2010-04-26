<?php if (empty($core_metrics)):?>
There are no metrics yet for this time period.
<?php else:?>
<table id="core_metrics_table">
	<tr>
	<?php if ($period == 'this_year'): ?>		
		<th scope="col">Month</th>
		<th scope="col">Year</th>
	<?php else: ?>
		<th scope="col">Month</th>
		<th scope="col">Day</th>
		<th scope="col">Year</th>
	<?php endif; ?>	
		<th scope="col">Page Views</th>
		<th scope="col">Visits</th>
		<th scope="col">Unique Visitors</th>

	</tr>
	<?php foreach($core_metrics as $row): ?>	
	<TR>
		<?php if ($period == 'this_year'): ?>
		<TD>
		<a href="<?php echo $this->makeLink(array('do' => 'base.reportDashboard', 'period' => 'month', 'year' => $row['year'], 'month' => $row['month'], 'site_id' => $site_id));?>">
		<?php echo $this->get_month_label($row['month']);?>
		</a>
		</TD>
		<TD><?php echo $row['year'];?></TD>
	<?php else: ?>
		<TD><?php echo $this->get_month_label($row['month']);?></TD>
		
		<TD>
		<a href="<?php echo $this->makeLink(array('do' => 'base.reportDashboard', 'period' => 'day', 'year' => $row['year'], 'month' => $row['month'], 'day' => $row['day'], 'site_id' => $site_id));?>">
		<?php echo $row['day'];?>
		</a>
		</TD>
		<TD><?php echo $row['year'];?></TD>
	<?php endif; ?>
		<TD><?php echo $row['page_views'];?></TD>
		<TD><?php echo $row['sessions'];?></TD>
		<TD><?php echo $row['unique_visitors'];?></TD>
	</TR>		
   <?php endforeach; ?>
</table>
<?php endif;?>