<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_dimensionDetail" id="">

    <div class="icon" style="float:left;">
        <img src="<?php echo $view->getBrowserIcon($view->properties['browser_family']);?>">
    </div>
    <div>
        <div class="title">
        <?php $view->out($view->properties['browser_family'], true, true); ?>
        </div>

    </div>
    <div style="clear:both;"></div>

</div>