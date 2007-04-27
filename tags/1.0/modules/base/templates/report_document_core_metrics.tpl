<? if (empty($core_metrics)):?>
There are no metrics yet for this time period.
<?else:?>
<table width="100%">
	<tr>
	<? if ($period == 'this_year' || $params['period'] == 'this_year'): ?>		
		<th scope="col">Month</th>
	<? else: ?>
		<th scope="col">Month</th>
		<th scope="col">Day</th>
		<th scope="col">Year</th>
	<? endif; ?>	
		<th scope="col">Page Views</th>
		<th scope="col">Visits</th>
		<th scope="col">Unique Visitors</th>

	</tr>
	<?php foreach($core_metrics as $row): ?>	
	<TR>
	<? if ($period == 'this_year' || $params['period'] == 'this_year'): ?>
		<TD>
		<a href="<?=$this->makeLink(array('view' => 'base.report', 'subview' => 'base.reportDocument', 'period' => 'month', 'year' => $row['year'], 'month' => $row['month'], 'document_id' => $document_id), true);?>">
		<?=$this->get_month_label($row['month']);?>
		</a>
		</TD>
	<? else: ?>
		<TD><?=$this->get_month_label($row['month']);?></TD>
		
		<TD>
		<a href="<?=$this->makeLink(array('view' => 'base.report', 'subview' => 'base.reportDocument', 'period' => 'day', 'year' => $row['year'], 'month' => $row['month'], 'day' => $row['day'], 'document_id' => $document_id), true);?>">
		<?=$row['day'];?>
		</a>
		</TD>
		<TD><?=$row['year'];?></TD>
	<? endif; ?>
		<TD><?=$row['page_views'];?></TD>
		<TD><?=$row['sessions'];?></TD>
		<TD><?=$row['unique_visitors'];?></TD>
	</TR>		
   <?php endforeach; ?>
</table>
<?endif;?>