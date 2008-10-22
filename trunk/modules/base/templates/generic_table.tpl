<script>

jQuery(document).ready(function() { 
	jQuery("#<?=$table_id;?>").tablesorter();
}); 
    
</script>

<table class="tablesorter <?=$table_class;?>" summary="" id="<?=$table_id;?>">
	<? if (!empty($caption)): ?>
	<caption><?=$caption;?></caption>
	<? endif;?>
	<thead>
		<TR>
			<?php foreach ($labels as $label): ?>
			<TH scope="<?=$th_scope;?>"><?=$label;?></TH>
			<?php endforeach;?>
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
			<?php foreach ($row as $item): ?>
			<TD><?=$item;?></TD>
			<?php endforeach;?>		
		</TR>
		<?php endforeach;?>
	</tbody>
</table>