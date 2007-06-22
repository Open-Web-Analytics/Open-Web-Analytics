<? if (!empty($top_referers)):?>
	<table class="data_table">
		<tr>
			<td class="col_item_label">Referring Web Page</td>
			<td class="col_label">Visits</td>
			
		</tr>
				
	<?php foreach($top_referers as $referer): ?>

		<TR>
			<TD class="item_cell"><a href="<?=$referer['url'];?>"><? if (!empty($referer['page_title'])):?><?=$this->truncate($referer['page_title'], 100, '...');?><?else:?><?=$this->truncate($referer['url'], 100, '...');?><? endif;?></a></TD>
			<TD class="data_cell"><?=$referer['count']?></TD>
			
		</TR>
				
	<?php endforeach; ?>
	</table>
<?else:?>
	There are no referering web pages for this time period.
<?endif;?>