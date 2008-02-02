<? if (!empty($top_exit_pages)):?>
<table class="data_table">
	<tr>
		<td class="col_item_label">Exit Page</td>
		<td class="col_label">Page Views</td>
	</tr>
				
	<?php foreach($top_exit_pages as $page): ?>
				
	<TR>
		<TD class="item_cell">
			<a href="<?=$this->makeLink(array('do' => 'base.reportDocument', 'document_id' => $page['id']), true);?>"><?=$page['page_title'];?></a> (<?=$page['page_type'];?>) </TD>
		<TD class="data_cell"><?=$page['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?else:?>
	<div class="no_data_msg">There are no Page Views for this time period.</div>
<?endif;?>