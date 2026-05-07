<?php if ($news):?>
<div style="text-align:left;">
    <?php foreach ($news as $newsItem): ?>
    <span class="info_text"><?php $this->out(date_create($newsItem->published_at)->format("M j, Y")); ?></span><br/>
    <a href="<?php $this->out($newsItem->html_url); ?>"><span class="h_label">Release <?php $this->out($newsItem->name); ?></span></a>
    <p>
        <?php foreach (preg_split('/\n|\r\n?/', $newsItem->body) as $line): ?>
        <?php $this->out($line); ?><br/>
        <?php endforeach;?>
    </p>
    <?php endforeach;?>
</div>
<a href="https://github.com/padams/Open-Web-Analytics/releases">More...</a>
<?php endif;?>