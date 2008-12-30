<?php if (!empty($top_pages)):?>

<table cellspacing="1" class="owa_sortableTable tablesorter">
	<thead>
		<tr>
			<th>Page</th>
			<th>Page Type</th>
			<th>Page Views</th>
		</tr>
	</thead>
	<tbody>			
		<?php foreach($top_pages as $page): ?>	
		<TR>
			<TD>
				<a href="<?=$this->makeLink(array('do' => 'base.reportDocument', 'document_id' => $page['document_id']), true);?>"><?=$this->truncate($page['page_title'], 100, '...');?></a>
			</TD>
			<TD><?=$page['page_type'];?></TD>
			<TD><?=$page['count']?></TD>
		</TR>	
		<?php endforeach; ?>
	</tbody>
</table>

<?=$this->makePagination($pagination, array('do' => 'base.reportContent'));?>

<?php else:?>
<div class="no_data_smg">There are no Page Views for this time period.</div>
<?php endif;?>