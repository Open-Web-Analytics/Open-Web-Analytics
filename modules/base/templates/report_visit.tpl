<div class="owa_reportSectionHeader">Visit Summary</div>
<div class="owa_reportSectionContent">
    <?php include('report_latest_visits.tpl');?>
</div>

<div class="owa_reportSectionHeader">Visit Clickstream</div>

<div class="owa_reportSectionContent">  
    
    <div class="propertyList">

        <?php foreach($clickstream->resultsRows as $s): $s = (array) $s;?>

        <dt><?php $this->out(date("H:i:s",$s['timestamp']));?></dt>
        <dd>
            <a href="<?php echo $this->makeLink(array('do' => 'base.reportDocument', 'pageUrl' => urlencode( $s['url'] ) ), true );?>"><span><?php echo $s['uri'];?></span></a>
        </dd>
        <BR><BR>
        <?php endforeach; ?>
    </div>

</div>
 