<div class="owa_reportSectionContent" style="width:700px;">
    <div class="owa_reportSectionHeader">Latest Visits</div>
    <?php include('report_latest_visits.tpl')?>
    <?php echo $this->makePagination($pagination, array('do' => $params['do']));?>
</div>