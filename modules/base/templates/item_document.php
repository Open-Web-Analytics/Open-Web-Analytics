<div class="owa_dimensionDetail" id="<?php echo $properties->get('id');?>">
    <div class="icon" style="float:left;">
        <img src="<?php echo $this->makeImageLink('base/i/document_icon_64.png');?>">
    </div>
    <div>
        <div class="title"><?php echo $properties->get('page_title');?></div>
        <div class="url">
            <?php echo $properties->get('url');?> &nbsp; <span class="moreLink"><a href="<?php echo $properties->get('url');?>">Visit Site &raquo;</a></span>
        </div>
        <div class="pagetype"><b>Page Type:</B> <?php echo $properties->get('page_type');?></div>
    </div>
    <div style="clear:both;"></div>
</div>