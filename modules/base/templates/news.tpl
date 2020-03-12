<?php if ($news):?>
<DIV style="text-align:left;">
<?php foreach ($news as $newsItem): ?>
<span class="info_text"><?php echo date_create($newsItem->published_at)->format("M j, Y"); ?></span><BR>
<a href="<?php echo $newsItem->html_url; ?>"><span class="h_label">Release <?php echo $newsItem->name; ?></span></a>
<P><?php echo nl2br($newsItem->body); ?></P>
<?php endforeach;?>
</DIV>
<a href="https://github.com/padams/Open-Web-Analytics/releases">More...</a>
<?php endif;?>