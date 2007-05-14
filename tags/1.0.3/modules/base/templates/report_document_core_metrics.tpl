<? if (empty($core_metrics)):?>
There are no metrics yet for this time period.
<?else:?>
<table class="data_table">
	<tr>
	<? if ($period == 'this_year' || $params['period'] == 'this_year'): ?>		
		<td class="col_item_labeL">Month</th>
	<? else: ?>
		<td class="col_item_label">Month</td>
		<td class="col_item_label">Day</td>
		<td class="col_item_label">Year</td>
	<? endif; ?>	
		<td class="col_label">Page Views</td>
		<td class="col_label">Visits</td>
		<td class="col_label">Unique Visitors</td>

	</tr>
	<?php foreach($core_metrics as $row): ?>	
	<TR>
	<? if ($period == 'this_year' || $params['period'] == 'this_year'): ?>
		<TD class="item_cell">
		<a href="<?=$this->makeLink(array('view' => 'base.report', 'subview' => 'base.reportDocument', 'period' => 'month', 'year' => $row['year'], 'month' => $row['month'], 'document_id' => $document_id), true);?>">
		<?=$this->get_month_label($row['month']);?>
		</a>
		</TD>
	<? else: ?>
		<TD class="item_cell"><?=$this->get_month_label($row['month']);?></TD>
		
		<TD class="item_cell">
		<a href="<?=$this->makeLink(array('view' => 'base.report', 'subview' => 'base.reportDocument', 'period' => 'day', 'year' => $row['year'], 'month' => $row['month'], 'day' => $row['day'], 'document_id' => $document_id), true);?>">
		<?=$row['day'];?>
		</a>
		</TD>
		<TD class="item_cell"><?=$row['year'];?></TD>
	<? endif; ?>
		<TD class="data_cell"><?=$row['page_views'];?></TD>
		<TD class="data_cell"><?=$row['sessions'];?></TD>
		<TD class="data_cell"><?=$row['unique_visitors'];?></TD>
	</TR>		
   <?php endforeach; ?>
</table>
<?endif;?>