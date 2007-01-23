<? if (!empty($domains)):?>
<table width="100%">
	<tr>
		<th scope="col">Domain</th>
		<th scope="col">Visits</th>
	</tr>
				
	<?php foreach($domains as $domain): ?>
		
	<TR>
		<TD><?=$domain['host'];?></td>
		<TD><?=$domain['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?else:?>
	There are no refering domains for this time period.
<?endif;?>