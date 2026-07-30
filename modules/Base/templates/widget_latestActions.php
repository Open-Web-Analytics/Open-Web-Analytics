<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="widget-latestActions">

<?php if ( $items ): ?>

<table border="0" style="width: <?php $view->out( $view->get( 'width' ) ); ?>;">

    <?php foreach ( $items as $k => $row ):?>
    <tr>
        <?php include('row_action.php'); ?>
    </tr>
    <?php endforeach; ?>

</table>


<?php else: ?>
<?php $view->out('No actions were performed during this time period.'); ?>
<?php endif;?>

</div>