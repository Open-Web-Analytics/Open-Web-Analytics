<?php if(!empty($visits)):?>
	<?php foreach($visits as $visit): ?>
		
		<?php include('report_visit_summary.tpl');?>
		<BR>
	<?php endforeach; ?>

<?php else:?>
	There were no visits during this time period.
<?php endif;?>