<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php if(!empty($view->visits)):?>
<table style="width:100%;">
    <?php foreach($view->visits->resultsRows as $row): 
	    $row = (array) $row;?>
        <TR>
        <?php include('row_visitSummary.php'); ?>
        </TR>
    <?php endforeach; ?>
</table>
<?php else:?>
    There were no visits during this time period.
<?php endif;?>