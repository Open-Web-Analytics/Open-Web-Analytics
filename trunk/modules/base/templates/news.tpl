<?php if ($news):?>
<DIV style="text-align:left;">
<?php foreach ($news['items'] as $item => $value): ?>
<a href="<?php echo $value['link'];?>"><span class="h_label"><?php echo $value['title'];?></span></a><span class="info_text"> - <?php echo $value['pubDate'];?></span>
<P><?php echo $value['description'];?></P>
<?php endforeach;?>
</DIV>
<?php endif;?>