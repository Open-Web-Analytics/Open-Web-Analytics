<? if (!empty($referers)):?>
<table cellpadding="0" cellspacing="0" class="data_table">
	<tr>
		<td class="col_item_label">Refering Sites</th>
		<td class="col_label">Visits</th>
		<td class="col_label">Page Views</th>
	</tr>
				
	<?php foreach($referers as $referer): ?>
		
	<TR>
		<TD class="item_cell">
		<a href="<?=$referer['url'];?>">
		<span class="inline_h3">
		<? if  (!empty($referer['page_title'])): ?>
		<?=$this->truncate($referer['page_title'], 90);?>
		<? else:?>
		<?=$this->truncate($referer['url'], 90);?>
		<? endif;?>
		</span>
		</a>
		<BR>
		<? if ($referer['snippet']):?>
		<?=$referer['snippet'];?><BR>
		<? endif;?>
		<span class="info_text"><?=$this->truncate($referer['url'], 80);?></span></td>
		<TD class="data_cell">
			<?=$referer['count']?>
		</TD>
		<TD class="data_cell">
			<?=$referer['page_views']?>
		</TD>
	</TR>
				
	<?php endforeach; ?>
</table>

<?else:?>
	There are no refering web pages for this time period.
<?endif;?>