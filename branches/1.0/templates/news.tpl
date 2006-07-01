<? if ($news):?>
	<table>
		<? foreach ($news['items'] as $item => $value): ?>
		<TR>
			<TD>
				<span class="info_text"><?=$value['pubDate'];?>:</span>
			</TD>
			<TD>
				<a href="<?=$value['link'];?>"><span class="h_label"><?=$value['title'];?></span></a>
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