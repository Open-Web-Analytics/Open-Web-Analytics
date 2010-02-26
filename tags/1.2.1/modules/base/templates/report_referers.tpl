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
			<a href="<?php echo $referer['url'];?>">
			<span class="inline_h3">
			<?php if  (!empty($referer['page_title'])): ?>
			<?php echo $this->truncate($referer['page_title'], 75);?>
			<?php else:?>
			<?php echo $this->truncate($referer['url'], 90);?>
			<?php endif;?>
			</span>
			</a>
			<BR>
			<?php if ($referer['snippet']):?>
			<?php echo $referer['snippet'];?><BR>
			<?php endif;?>
			<span class="info_text"><?php echo $this->truncate($referer['url'], 80);?></span></td>
			<TD class="data_cell">
				<?php echo $referer['count']?>
			</TD>
			<TD class="data_cell">
				<?php echo $referer['page_views']?>
			</TD>
		</TR>		
		<?php endforeach; ?>
	</tbody>
</table>

<?php echo $this->makePagination($pagination, array('do' => 'base.reportReferringSites'));?>

<?php else:?>
	There are no refering web pages for this time period.
<?php endif;?>