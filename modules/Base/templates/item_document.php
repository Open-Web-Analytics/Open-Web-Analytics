<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_dimensionDetail" id="<?php echo $view->properties->get('id');?>">
    <div class="icon" style="float:left;">
        <img src="<?php echo $view->makeImageLink('base/i/document_icon_64.png');?>">
    </div>
    <div>
        <div class="title"><?php $view->out( $view->properties->get('page_title') );?></div>
        <div class="url">
            <?php $view->out( $view->properties->get('url') );?> &nbsp; <span class="moreLink"><a href="<?php $view->safeHref( $view->properties->get('url') );?>">Visit Site &raquo;</a></span>
        </div>
        <div class="pagetype"><b>Page Type:</B> <?php $view->out( $view->properties->get('page_type') );?></div>
    </div>
    <div style="clear:both;"></div>
</div>