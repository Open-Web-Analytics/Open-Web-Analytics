<? if (!empty($rows)): ?>

<script>

jQuery(document).ready(function() { 
	jQuery("#<?=$table_id;?>").tablesorter();
}); 
    
</script>

<table class="<?=$sort_table_class;?> <?=$table_class;?>" summary="" id="<?=$table_id;?>">
	<? if (!empty($caption)): ?>
	<caption><?=$caption;?></caption>
	<? endif;?>
	<thead>
		<TR>
			<?php if (!empty($labels)):?>
			<?php foreach ($labels as $label): ?>
			<TH scope="<?=$th_scope;?>"><?=$label;?></TH>
			<?php endforeach;?>
			<?php endif;?>
		</TR>
	</thead>
	<? if (!empty($table_footer)): ?>
	<tfoot>
		<td colspan="<?=$col_count;?>"><?=$table_footer;?></td>
	</tfoot>
	<? endif;?>
	<tbody>
		<?php foreach ($rows as $row):?>
		<TR>
			<?php if (!empty($table_row_template)): ?>
			<?php include($this->setTemplate($table_row_template));?>
			<?php else: ?>
			<?php foreach ($row as $item): ?>
			<TD><?=$item;?></TD>
			<?php endforeach;?>	
			<?php endif;?>	
		</TR>
		<?php endforeach;?>
	</tbody>
</table>

<? else: ?>

<div class="owa_status-msg">No data to display.</div>

<? endif;?>