<div class="owa_dimensionDetail refererDetailPanel" id="">
    <div class="icon" style="float:left;">
        <img src="<?php echo $this->makeImageLink('base/i/referral_icon_64.png'); ?>">
    </div>
    <div>
        <div class="title">
        <?php
            if ($properties['page_title']) {
                $this->out($properties['page_title'], true, true);
            } else {
                $this->out('No Title', false);
            }
        ?>
        </div>
        <div class="url">
            <?php $this->out($properties['url']);?> &nbsp; <span class="moreLink"><a href="<?php $this->out( $properties['url'] );?>">Visit Site &raquo;</a></span>
        </div>
        <div class="snippet"><?php $this->out($properties['snippet'], false);?></div>
    </div>
    <div style="clear:both;"></div>
</div>