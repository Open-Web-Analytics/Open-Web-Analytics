<? if ($news):?>
	<table>
		<? foreach ($news['items'] as $item => $value): ?>
		<TR>
			<TD colspan="2">
				<a href="<?=$value['link'];?>"><span class="h_label"><?=$value['title'];?></span></a> <span class="info_text">- <?=$value['pubDate'];?></span>
			</TD>
		</TR>
		<TR>
			<TD></TD>
			<TD>
			<?=$value['description'];?>
			</TD>
		</TR>
		<? endforeach;?>
	</table>		
<?endif;?>