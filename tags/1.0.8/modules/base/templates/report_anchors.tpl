<? if (!empty($anchors)):?>
<table width="100%">
	<tr>
		<th scope="col">Anchor Text</th>
		<th scope="col">Visits</th>
	</tr>
				
	<?php foreach($anchors as $anchor): ?>
		
	<TR>
		<TD><?=$anchor['refering_anchortext'];?></td>
		<TD><?=$anchor['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?else:?>
	There are no refering anchors for this time period.
<?endif;?>