<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_reportSectionHeader">Visit Summary</div>
<div class="owa_reportSectionContent">
    <?php include('report_latest_visits.php');?>
</div>

<div class="owa_reportSectionHeader">Visit Clickstream</div>

<div class="owa_reportSectionContent">  
    
    <div class="propertyList">

        <?php foreach($view->clickstream->resultsRows as $s): $s = (array) $s;?>

        <dt><?php $view->out(date("H:i:s",$s['timestamp']));?></dt>
        <dd>
            <a href="<?php echo $view->makeLink(array('do' => 'base.report', 'reportId' => 'document', 'pagePath' => urlencode( $s['uri'] ) ), true );?>"><span><?php echo $s['uri'];?></span></a>
        </dd>
        <BR><BR>
        <?php endforeach; ?>
    </div>

</div>
 