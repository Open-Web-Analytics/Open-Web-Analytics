<?php if(!empty($visits)):?>
	<?php foreach($visits as $visit): ?>
		
	<?php include('report_visit_summary.tpl');?>
	
<?php endforeach; ?>

<?php endif;?>