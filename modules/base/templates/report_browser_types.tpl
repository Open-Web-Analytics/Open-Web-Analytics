<? if (!empty($browser_types)):?>
<table class="data_table">
	<tr>
		
		<td class="col_item_label">Browser Type</td>
		<td class="col_label">Visits</td>
	</tr>
				
	<?php foreach($browser_types as $browser =>$type): ?>
		
	<TR>
		
		<TD class="item_cell">
			<?=$this->choose_browser_icon($type['browser_type']);?> &nbsp;
		<? if (empty($type['browser_type'])):?>
			Unknown Browser
		<? else:?>
			 <?=$type['browser_type'];?>
		<?endif;?>
		</TD>
		<TD class="data_cell">
			<?=$type['count'];?>
		</TD>		
	</TR>
				
	<?php endforeach; ?>

	</table>
<?else:?>
	There are no browsers for this time period.
<?endif;?>