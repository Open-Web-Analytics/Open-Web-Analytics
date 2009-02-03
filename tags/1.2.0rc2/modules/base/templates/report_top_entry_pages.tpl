<?php if (!empty($top_entry_pages)):?>
<table class="tablesorter">
	<thead>
		<tr>
			<th>Entry Page</th>
			<th>Page Views</th>
		</tr>
	</thead>	
	<tbody>				
		<?php foreach($top_entry_pages as $page): ?>
					
		<TR>
			<TD>
				<a href="<?=$this->makeLink(array('do' => 'base.reportDocument', 'document_id' => $page['id']), true);?>"><?=$page['page_title'];?></a> (<?=$page['page_type'];?>)
			</TD>
			<TD><?=$page['count']?></TD>
		</TR>
					
		<?php endforeach; ?>
	</tbody>
</table>
<?php else:?>
<div class="no_data_msg">There are no Page Views for this time period.</div>
<?php endif;?>