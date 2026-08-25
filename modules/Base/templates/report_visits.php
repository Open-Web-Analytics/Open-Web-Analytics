<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_reportSectionContent" style="width:700px;">
    <div class="owa_reportSectionHeader">Latest Visits</div>
    <?php include('report_latest_visits.php')?>
    <?php echo $view->makePagination($view->pagination, array('do' => $view->params['do'] ?? ''));?>
</div>