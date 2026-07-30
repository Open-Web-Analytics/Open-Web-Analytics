<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_dimensionDetail refererDetailPanel" id="">
    <div class="icon" style="float:left;">
        <img src="<?php echo $view->makeImageLink('base/i/referral_icon_64.png'); ?>">
    </div>
    <div>
        <div class="title">
        <?php
            if ($view->properties['page_title']) {
                $view->out($view->properties['page_title'], true, true);
            } else {
                $view->out('No Title', false);
            }
        ?>
        </div>
        <div class="url">
            <?php $view->out($view->properties['url']);?> &nbsp; <span class="moreLink"><a href="<?php $view->safeHref( $view->properties['url'] );?>">Visit Site &raquo;</a></span>
        </div>
        <div class="snippet"><?php $view->out($view->properties['snippet'], false);?></div>
    </div>
    <div style="clear:both;"></div>
</div>