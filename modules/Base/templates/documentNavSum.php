<?php /** @var \OWA\Core\ViewScope $view */ ?>
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