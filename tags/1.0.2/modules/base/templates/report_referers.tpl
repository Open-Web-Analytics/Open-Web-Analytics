<? if (!empty($referers)):?>
<table width="100%">
	<tr>
		<th scope="col">Referers</th>
		<th scope="col">Visits</th>
		<th scope="col">Page Views</th>
	</tr>
				
	<?php foreach($referers as $referer): ?>
		
	<TR>
		<TD>
		<a href="<?=$referer['url'];?>">
		<span class="inline_h2">
		<? if  (!empty($referer['page_title'])): ?>
		<?=$this->truncate($referer['page_title'], 90);?>
		<? else:?>
		<?=$this->truncate($referer['url'], 90);?>
		<? endif;?>
		</span>
		</a>
		<BR>
		<?=$referer['snippet'];?><BR>
		<span class="info_text"><?=$this->truncate($referer['url'], 80);?></span></td>
		<TD valign="top">
			<div class="visitor_info_box pages_box">
				<span class="large_number"><?=$referer['count']?></span><BR>
				<span class="info_text">Visits</span>
			</div>
		</TD>
		<TD valign="top">
			<div class="visitor_info_box pages_box">
				<span class="large_number"><?=$referer['page_views']?></span><BR>
				<span class="info_text">Pages</span>
			</div>
		</TD>
	</TR>
				
	<?php endforeach; ?>

	</table>
<?else:?>
	There are no refering web pages for this time period.
<?endif;?>