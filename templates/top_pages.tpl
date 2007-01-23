<? if (!empty($pages)):?>
<table width="100%">
	<tr>
		<th scope="col">PageTitle</th>
		<th scope="col">Views</th>
	</tr>
				
	<?php foreach($pages as $page): ?>
				
	<TR>
		<TD>
			<a href="<?=$this->make_report_link('document_report.php', array('document_id' => $page['id'], 'site_id' => $params['site_id'], 'period' => $params['period']));?>"><?=$page['page_title'];?></a> (<?=$page['page_type'];?>) </TD>
		<TD><?=$page['count']?></TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?else:?>
	There are no Page Views for this time period.
<?endif;?>