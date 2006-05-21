<table width="100%">
	<tr>
	<? if ($period == 'this_year'): ?>		
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
	<?php foreach($data as $row): ?>	
	<TR>
	<? if ($period == 'this_year'): ?>
		<TD><?=$this->get_month_label($row['month']);?></TD>
	<? else: ?>
		<TD><?=$this->get_month_label($row['month']);?></TD>
		<TD><?=$row['day'];?></TD>
		<TD><?=$row['year'];?></TD>
	<? endif; ?>
		<TD><?=$row['page_views'];?></TD>
		<TD><?=$row['sessions'];?></TD>
		<TD><?=$row['unique_visitors'];?></TD>
	</TR>		
   <?php endforeach; ?>
</table>