<?php if (!empty($top_referers)):?>
	<table class="tablesorter">
		<thead>
			<tr>
				<th>Referring Web Page</th>
				<th>Visits</th>
			</tr>
		</thead>					
		</tbody>
		<?php foreach($top_referers as $referer): ?>
			<TR>
				<TD class="item_cell"><a href="<?=$referer['url'];?>"><? if (!empty($referer['page_title'])):?><?=$this->truncate($referer['page_title'], 100, '...');?><?else:?><?=$this->truncate($referer['url'], 100, '...');?><? endif;?></a></TD>
				<TD class="data_cell"><?=$referer['count']?></TD>
				
			</TR>
		<?php endforeach; ?>
		</tbody>					
	</table>
<?php else:?>
	There are no referering web pages for this time period.
<?php endif;?>