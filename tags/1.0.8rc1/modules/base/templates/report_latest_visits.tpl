<? if(!empty($visits)):?>
	<?php foreach($visits as $visit): ?>
		
	<? include('report_visit_summary.tpl');?>
	
<?php endforeach; ?>

<?else:?>
	There were no visits during this time period.
<? endif;?>