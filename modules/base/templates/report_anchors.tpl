<?php if (!empty($anchors)):?>
<table width="100%">
	<tr>
		<th scope="col">Anchor Text</th>
		<th scope="col">Visits</th>
	</tr>
				
	<?php foreach($anchors as $anchor): ?>
		
	<TR>
		<TD><?php echo $anchor['refering_anchortext'];?></td>
		<TD><?php echo $anchor['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?php else:?>
	There are no refering anchors for this time period.
<?php endif;?>