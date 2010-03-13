<?php if (!empty($top_exit_pages)):?>
<table class="tablesorter">
	<thead>
		<tr>
			<th>Exit Page</th>
			<th>Page Views</th>
		</tr>
	</thead>
	<tbody>				
		<?php foreach($top_exit_pages as $page): ?>
					
		<TR>
			<TD>
				<a href="<?php echo $this->makeLink(array('do' => 'base.reportDocument', 'document_id' => $page['id']), true);?>"><?php echo $page['page_title'];?></a> (<?php echo $page['page_type'];?>) </TD>
			<TD><?php echo $page['count']?></TD>
		</TR>
					
		<?php endforeach; ?>
	</tbody>
</table>
<?php else:?>
	<div class="no_data_msg">There are no Page Views for this time period.</div>
<?php endif;?>