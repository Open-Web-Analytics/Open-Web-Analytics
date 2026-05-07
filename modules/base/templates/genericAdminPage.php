<div id="<?php echo $dom_id;?>" class="owa_reportContainer">

    <div class="reportSectionContainer">
        <div id="owa_timePeriodControl" class="owa_reportPeriod" style="float:right;"></div>
        <div id="liveViewSwitch" style="width:auto;float:right; padding-right:30px;"></div>
        <div class="owa_reportTitle"><?php echo $title;?><span class="titleSuffix"><?php echo $this->get('titleSuffix');?></span></div>

        <div class="clear"></div>
        <?php echo $subview;?>

    </div>

</div>