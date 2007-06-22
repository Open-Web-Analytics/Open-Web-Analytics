<? if (empty($feed_trend)):?>
There are no metrics yet for this time period.
<?else:?>
<table class="data_table">
	<tr>
	<? if ($period == 'this_year'): ?>		
		<td class="col_item_label">Month</td>
		<td class="col_item_label">Year</td>
	<? else: ?>
		<td class="col_item_label">Month</td>
		<td class="col_item_label">Day</td>
		<td class="col_item_label">Year</td>
	<? endif; ?>	
		<td class="col_label">Feed Fetches</td>
		<td class="col_label">Unique Readers</td>

	</tr>
	<?php foreach($feed_trend as $row): ?>	
	<TR>
	<? if ($period == 'this_year'): ?>
		<TD class="item_cell">
		<?=$this->get_month_label($row['month']);?>
		<TD class="item_cell"><?=$row['year'];?></TD>
		</TD>
	<? else: ?>
		<TD class="item_cell"><?=$this->get_month_label($row['month']);?></TD>		
		<TD class="item_cell"><?=$row['day'];?></TD>
		<TD class="item_cell"><?=$row['year'];?></TD>
	<? endif; ?>
		<TD class="data_cell"><?=$row['fetch_count'];?></TD>
		<TD class="data_cell"><?=$row['reader_count'];?></TD>
	</TR>		
   <?php endforeach; ?>
</table>
<?endif;?>