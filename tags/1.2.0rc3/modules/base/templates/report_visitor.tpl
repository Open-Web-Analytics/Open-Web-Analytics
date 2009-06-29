<div class="owa_reportSectionHeader">Prior Visits for Visitor: <?=$visitor_id;?></div>
		
<div class="owa_reportSectionContent">		
	<? include('report_latest_visits.tpl')?>
	
	<?=$this->makePagination($pagination, array('do' => 'base.reportVisitor', 'visitor_id' => $visitor_id));?>
</div>	
				

