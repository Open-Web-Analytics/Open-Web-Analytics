<?php if (!empty($browser_types)):?>
<table class="tablesorter">
	<thead>
		<tr>
			
			<th>Browser Type</th>
			<th>Visits</th>
		</tr>
	</thead>
	<tbody>		
		<?php foreach($browser_types as $browser =>$type): ?>
			
		<TR>
			
			<TD class="item_cell">
				<?php echo $this->choose_browser_icon($type['browser_type']);?> &nbsp;
			<?php if (empty($type['browser_type'])):?>
				Unknown Browser
			<?php else:?>
				 <?php echo $type['browser_type'];?>
			<?php endif;?>
			</TD>
			<TD class="data_cell">
				<?php echo $type['count'];?>
			</TD>		
		</TR>
					
		<?php endforeach; ?>
	</tbody>	
	</table>
<?php else:?>
	There are no browsers for this time period.
<?php endif;?>