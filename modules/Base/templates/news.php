<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php if ($view->news):?>
<div style="text-align:left;">
    <?php foreach ($view->news as $newsItem): ?>
    <span class="info_text"><?php $view->out(date_create($newsItem->published_at)->format("M j, Y")); ?></span><br/>
    <a href="<?php $view->out($newsItem->html_url); ?>"><span class="h_label">Release <?php $view->out($newsItem->name); ?></span></a>
    <p>
        <?php foreach (preg_split('/\n|\r\n?/', $newsItem->body) as $line): ?>
        <?php $view->out($line); ?><br/>
        <?php endforeach;?>
    </p>
    <?php endforeach;?>
</div>
<a href="https://github.com/padams/Open-Web-Analytics/releases">More...</a>
<?php endif;?>