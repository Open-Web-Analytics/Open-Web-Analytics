<?php if (empty($feed_trend)):?>
There are no metrics yet for this time period.
<?php else:?>
<table class="tablesorter">
	<thead>
		<tr>	
			<th>Date</th>
			<th>Feed Fetches</th>
			<th>Unique Readers</th>
		</tr>
	</thead>
	<tbody>
		<?php foreach($feed_trend as $row): ?>	
		<TR>
			<TD><?=$this->get_month_label($row['month']);?> <?=$row['day'];?> <?=$row['year'];?></TD>
			<TD><?=$row['fetch_count'];?></TD>
			<TD><?=$row['reader_count'];?></TD>
		</TR>		
	   <?php endforeach; ?>
   </tbody>
</table>
<?php endif;?>