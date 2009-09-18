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
				<a href="<?php echo $this->makeLink(array('do' => 'base.reportDocument', 'document_id' => $page['document_id']), true);?>"><?php echo $this->truncate($page['page_title'], 100, '...');?></a>
			</TD>
			<TD><?php echo $page['page_type'];?></TD>
			<TD><?php echo $page['count']?></TD>
		</TR>	
		<?php endforeach; ?>
	</tbody>
</table>

<?php echo $this->makePagination($pagination, array('do' => 'base.reportContent'));?>

<?php else:?>
<div class="no_data_smg">There are no Page Views for this time period.</div>
<?php endif;?>