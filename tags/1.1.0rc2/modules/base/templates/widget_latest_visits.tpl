<? if(!empty($visits)):?>
	<?php foreach($visits as $visit): ?>
		
	<? include('report_visit_summary.tpl');?>
	
<?php endforeach; ?>

<? endif;?>