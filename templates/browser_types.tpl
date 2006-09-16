<? if (!empty($browser_types)):?>
<table width="100%">
	<tr>
		<th scope="col">Browser Type</th>
		<th scope="col">Visits</th>
	</tr>
				
	<?php foreach($browser_types as $browser =>$type): ?>
		
	<TR>
		<TD>
		<? if (empty($type['browser_type'])):?>
		Unknown Browser Type
		<? else:?>
		<?=$this->choose_browser_icon($type['browser_type']);?> <?=$type['browser_type'];?>
		<?endif;?>
		</td>
		<TD valign="top">
			<?=$type['count'];?>
		</TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?else:?>
	There are no browsers for this time period.
<?endif;?>