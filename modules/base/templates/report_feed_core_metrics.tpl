<? if (empty($feed_trend)):?>
There are no metrics yet for this time period.
<?else:?>
<table width="100%">
	<tr>
	<? if ($period == 'this_year'): ?>		
		<th scope="col">Month</th>
		<th scope="col">Year</th>
	<? else: ?>
		<th scope="col">Month</th>
		<th scope="col">Day</th>
		<th scope="col">Year</th>
	<? endif; ?>	
		<th scope="col">Feed Fetches</th>
		<th scope="col">Unique Readers</th>

	</tr>
	<?php foreach($feed_trend as $row): ?>	
	<TR>
	<? if ($period == 'this_year'): ?>
		<TD>
		<?=$this->get_month_label($row['month']);?>
		<TD><?=$row['year'];?></TD>
		</TD>
	<? else: ?>
		<TD><?=$this->get_month_label($row['month']);?></TD>		
		<TD><?=$row['day'];?></TD>
		<TD><?=$row['year'];?></TD>
	<? endif; ?>
		<TD><?=$row['fetch_count'];?></TD>
		<TD><?=$row['reader_count'];?></TD>
	</TR>		
   <?php endforeach; ?>
</table>
<?endif;?>