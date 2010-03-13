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
				<TD class="item_cell"><a href="<?php echo $referer['url'];?>"><?php if (!empty($referer['page_title'])):?><?php echo $this->truncate($referer['page_title'], 100, '...');?><?php else:?><?php echo $this->truncate($referer['url'], 100, '...');?><?php endif;?></a></TD>
				<TD class="data_cell"><?php echo $referer['count']?></TD>
				
			</TR>
		<?php endforeach; ?>
		</tbody>					
	</table>
<?php else:?>
	There are no referering web pages for this time period.
<?php endif;?>