<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div id="<?php echo $view->dom_id;?>" class="owa_reportContainer">

    <div class="reportSectionContainer">
        <div id="owa_timePeriodControl" class="owa_reportPeriod" style="float:right;"></div>
        <h1 class="owa_reportTitle"><?php $view->out( $view->title );?><span class="titleSuffix"><?php $view->out( $view->get('titleSuffix') );?></span></h1>

        <div class="clear"></div>
        <?php echo $view->subview;?>

    </div>

</div>