<?php if (!empty($keywords)):?>
<table width="100%">
	<tr>
		<th scope="col">Keyword</th>
		<th scope="col">Visits</th>
	</tr>
				
	<?php foreach($keywords as $keyword): ?>
		
	<TR>
		<TD><?php echo $keyword['query_terms'];?></td>
		<TD><?php echo $keyword['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?php else:?>
	There are no Keywords for this time period.
<?php endif;?>