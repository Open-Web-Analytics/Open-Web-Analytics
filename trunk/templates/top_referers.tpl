<? if (!empty($data)):?>
	<table width="100%">
		<tr>
			<th scope="col">Web Page Title</th>
			<th scope="col">Visits</th>
			
		</tr>
				
	<?php foreach($data as $referer): ?>

		<TR>
			<TD><a href="<?=$referer['url'];?>"><? if (!empty($referer['page_title'])):?><?=$referer['page_title'];?><?else:?><?=$this->truncate($referer['url'], 100, '...');?><? endif;?></a></TD>
			<TD><?=$referer['count']?></TD>
			
		</TR>
				
	<?php endforeach; ?>
	</table>
<?else:?>
	There are no referering web pages for this time period.
<?endif;?>