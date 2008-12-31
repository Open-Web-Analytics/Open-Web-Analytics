<?php if (empty($feed_trend)):?>
There are no metrics yet for this time period.
<?else:?>
<table class="tablesorter">
	<thead>
	<tr>
	<?php if ($period == 'this_year'): ?>		
		<th>Month</th>
		<th>Year</th>
	<?php else: ?>
		<th>Month</th>
		<th>Day</th>
		<th>Year</th>
	<?php endif; ?>	
		<th>Feed Fetches</th>
		<th>Unique Readers</th>

	</tr>
	</thead>
	<tbody>
	<?php foreach($feed_trend as $row): ?>	
	<TR>
	<?php if ($period == 'this_year'): ?>
		<TD class="item_cell">
		<?=$this->get_month_label($row['month']);?>
		<TD class="item_cell"><?=$row['year'];?></TD>
		</TD>
	<?php else: ?>
		<TD class="item_cell"><?=$this->get_month_label($row['month']);?></TD>		
		<TD class="item_cell"><?=$row['day'];?></TD>
		<TD class="item_cell"><?=$row['year'];?></TD>
	<?php endif; ?>
		<TD class="data_cell"><?=$row['fetch_count'];?></TD>
		<TD class="data_cell"><?=$row['reader_count'];?></TD>
	</TR>		
   <?php endforeach; ?>
   </tbody>
</table>
<?php endif;?>