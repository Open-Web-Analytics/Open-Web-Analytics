<?php if ($news):?>
<DIV style="text-align:left;">
<?php foreach ($news['items'] as $item => $value): ?>
<span class="info_text"><?php echo $value['pubDate'];?></span><BR>
<a href="<?php echo $value['link'];?>"><span class="h_label"><?php echo $value['title'];?></span></a> 
<P><?php echo $value['description'];?></P>
<?php endforeach;?>
</DIV>
<?php endif;?>