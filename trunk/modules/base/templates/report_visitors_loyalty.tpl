
<div class="owa_reportSectionContent">
	<div class="owa_reportSectionHeader">First Visit Distribution</div>
	<?php include('report_visitors_age.tpl');?>

	<?php echo $this->makePagination($pagination, array('do' => 'base.reportVisitorLoyalty'));?>

</div>

