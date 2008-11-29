<? if ($news):?>
<DIV style="text-align:left;">
<? foreach ($news['items'] as $item => $value): ?>
<a href="<?=$value['link'];?>"><span class="h_label"><?=$value['title'];?></span></a><span class="info_text"> - <?=$value['pubDate'];?></span>
<P><?=$value['description'];?></P>
<? endforeach;?>
</DIV>
<? endif;?>