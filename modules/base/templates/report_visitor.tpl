<div class="owa_reportSectionHeader">Prior Visits for Visitor: <?php echo $visitor_id;?></div>
		
<div class="owa_reportSectionContent">		
	<?php include('report_latest_visits.tpl')?>
	
	<?php echo $this->makePagination($pagination, array('do' => 'base.reportVisitor', 'visitor_id' => $visitor_id));?>
</div>	
				

