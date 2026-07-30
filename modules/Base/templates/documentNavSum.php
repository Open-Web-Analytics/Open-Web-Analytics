<?php
/**
 * @var \OWA\Core\ViewScope $view
 * @var array $row  from the includer's scope -- see below
 *
 * documentNavSum.php is included from row_visitSummary.php, which is itself
 * included inside report_latest_visits.php's `foreach (... as $row)`. $row is
 * therefore the loop value from the INCLUDER's scope, not a view var.
 *
 * PHPStan analyses every template standalone, so it cannot see an includer's
 * locals; the @var above declares the contract rather than suppressing it.
 */
?>
<div style="background-image: url('<?php echo $view->makeImageLink('base/i/document_icon.gif');?>');background-repeat: no-repeat; padding:5px 5px 5px 35px; background-position:13px 5px;">
    <span class="inline_h4">
        <a href="<?php $view->safeHref( $row['document_url'] );?>"><?php $view->out( $row['document_page_title'] );?></a> &nbsp;
        <?php
	        
	        if ( $view->isValueSet( $row['document_page_type'] ) ) {
	        
	        	echo '('. $row['document_page_type'] .')';
	        	 
	        
	        } else {
		        
		        echo $row['document_page_type'];
	        }
	        
	        ?>

        <BR>
        <span class="externalUrl">
            <?php $view->out( $view->truncate( $row['document_url'], 80, '…') );?>
        </span>
    </span>
</div>