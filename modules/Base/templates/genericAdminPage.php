<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div id="<?php echo $view->dom_id;?>" class="owa_reportContainer">

    <div class="reportSectionContainer">
        <div id="owa_timePeriodControl" class="owa_reportPeriod" style="float:right;"></div>
        <div id="liveViewSwitch" style="width:auto;float:right; padding-right:30px;"></div>
        <div class="owa_reportTitle"><?php echo $view->title;?><span class="titleSuffix"><?php echo $view->get('titleSuffix');?></span></div>

        <div class="clear"></div>
        <?php echo $view->subview;?>

    </div>

</div>