<? if (!empty($top_pages)):?>
<table class="data_table">
	<tr>
		<td class="col_item_label">Most Popular Pages</td>
		<td class="col_label">Page Views</td>
	</tr>
				
	<?php foreach($top_pages as $page): ?>
				
	<TR>
		<TD class="item_cell">
			<a href="<?=$this->makeLink(array('do' => 'base.reportDocument', 'document_id' => $page['document_id']), true);?>"><?=$this->truncate($page['page_title'], 100, '...');?></a> (<?=$page['page_type'];?>) </TD>
		<TD class="data_cell"><?=$page['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?else:?>
	<div class="no_data_smg">There are no Page Views for this time period.</div>
<?endif;?>