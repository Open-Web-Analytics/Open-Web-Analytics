<? if (!empty($browser_types)):?>
<table width="100%" border="0">
	<tr>
		
		<th colspan="2" scope="col">Browser</th>
		<th scope="col">Visits</th>
	</tr>
				
	<?php foreach($browser_types as $browser =>$type): ?>
		
	<TR>
		<TD class="tiny_icon">
			<?=$this->choose_browser_icon($type['browser_type']);?>
		</TD>
		<TD>
		<? if (empty($type['browser_type'])):?>
			Unknown Browser
		<? else:?>
			 <?=$type['browser_type'];?>
		<?endif;?>
		</TD>
		<TD>
			<?=$type['count'];?>
		</TD>		
	</TR>
				
	<?php endforeach; ?>

	</table>
<?else:?>
	There are no browsers for this time period.
<?endif;?>