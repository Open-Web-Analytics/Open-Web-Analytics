<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
    /*
     * The referring page.
     *
     * Title and snippet used to sit here too. Both came from the referral
     * crawl, which OWA no longer does: page_title is now the literal string
     * "(not set)" that RefererHandlers writes as its default, and snippet is
     * empty on every row. A panel headed "(not set)" above a blank line said
     * less than no panel, so what is left is the one thing that is real -- the
     * URL, and a way to go there.
     *
     * The columns are untouched; a row crawled before the removal keeps its
     * title. Nothing reads it here any more.
     */
?>
<div class="owa_dimensionDetail refererDetailPanel" id="">
    <div class="icon" style="float:left;">
        <img src="<?php echo $view->makeImageLink('base/i/referral_icon_64.png'); ?>">
    </div>
    <div>
        <div class="url">
            <?php $view->out($view->properties['url']);?> &nbsp; <span class="moreLink"><a href="<?php $view->safeHref( $view->properties['url'] );?>">Visit Site &raquo;</a></span>
        </div>
    </div>
    <div style="clear:both;"></div>
</div>
