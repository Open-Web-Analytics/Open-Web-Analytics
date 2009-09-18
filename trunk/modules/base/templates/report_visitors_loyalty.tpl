<div class="owa_reportSectionHeader">There were <?php echo $summary_stats['unique_visitors'];?> unique visitors of this web site.</div>
<div class="owa_reportSectionContent">
<?php include('report_dashboard_summary_stats.tpl');?>
</div>

<div class="owa_reportSectionHeader">First Visit Distribution</div>
<div class="owa_reportSectionContent">
<?php include('report_visitors_age.tpl');?>

<?php echo $this->makePagination($pagination, array('do' => 'base.reportVisitorLoyalty'));?>

</div>

