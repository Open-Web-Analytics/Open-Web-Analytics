<TABLE class="generic-data-table">
	<TR>
		<?php foreach ($labels as $label): ?>
		<TH><?=$label;?></TH>
		<?php endforeach;?>
	</TR>
	<?php foreach ($rows as $row): ?>
	<TR>
		<?php foreach ($row as $item): ?>
		<TD><?=$item;?></TD>
		<?php endforeach;?>		
	</TR>
	<?php endforeach;?>
</TABLE>