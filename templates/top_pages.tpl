<table width="100%">
	<tr>
		<th scope="col">PageTitle</th>
		<th scope="col">Views</th>
	</tr>
				
	<?php foreach($top_pages as $page): ?>
				
	<TR>
		<TD><a href="<?=$page['uri'];?>"><?=$page['page_title'];?></a> (<?=$page['page_type'];?>)</TD>
		<TD><?=$page['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>