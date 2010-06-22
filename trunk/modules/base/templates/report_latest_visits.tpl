<?php if(!empty($visits)):?>
	<?php foreach($visits->resultsRows as $visit): ?>
		
		<?php include('report_visit_summary.tpl');?>
		<BR>
	<?php endforeach; ?>

	<?php echo $this->makePaginationFromResultSet($visits);?>
<?php else:?>
	There were no visits during this time period.
<?php endif;?>