<?php if (!empty($referers)):?>
<table class="tablesorter">
	<thead>
		<tr>
			<th>Refering Site</th>
			<th>Visits</th>
			<th>Page Views</th>
		</tr>
	</thead>
	<tbody>			
		<?php foreach($referers as $referer): ?>
			
		<TR>
			<TD class="item_cell">
			<a href="<?=$referer['url'];?>">
			<span class="inline_h3">
			<? if  (!empty($referer['page_title'])): ?>
			<?=$this->truncate($referer['page_title'], 75);?>
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
	</tbody>
</table>

<?=$this->makePagination($pagination, array('do' => 'base.reportReferringSites'));?>

<?php else:?>
	There are no refering web pages for this time period.
<?php endif;?>