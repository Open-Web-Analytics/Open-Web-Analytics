<? if (!empty($keywords)):?>
<table width="100%">
	<tr>
		<th scope="col">Keyword</th>
		<th scope="col">Visits</th>
	</tr>
				
	<?php foreach($keywords as $keyword): ?>
		
	<TR>
		<TD><?=$keyword['query_terms'];?></td>
		<TD><?=$keyword['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?else:?>
	There are no Keywords for this time period.
<?endif;?>