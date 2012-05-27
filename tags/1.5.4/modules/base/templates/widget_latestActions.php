<div class="widget-latestActions">

<?php if ( $items ): ?>
	
<table border="0" style="width: <?php $this->out( $this->get( 'width' ) ); ?>;">
	
	<?php foreach ( $items as $k => $row ):?>
	<tr>
		<?php include('row_action.php'); ?>
	</tr>
	<?php endforeach; ?>

</table>
	
	
<?php else: ?>
<?php $this->out('No actions were performed during this time period.'); ?>
<?php endif;?>

</div>