<?php if(!empty($visits)):?>
<table>
	<?php foreach($visits->resultsRows as $row): ?>
		<TR>
		<?php include('row_visitSummary.tpl');?>
		</TR>
	<?php endforeach; ?>
</table>
	<?php //echo $this->makePaginationFromResultSet($visits);?>
<?php else:?>
	There were no visits during this time period.
<?php endif;?>