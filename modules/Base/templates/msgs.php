<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php if( ! empty( $view->status_msg ) ):?>
<div class="status">
    <?php if (isset($view->status_msg['headline'])) : ?><b><?php $view->out( $view->status_msg['headline'] ); ?>!</b><?php endif; ?>
    <?php if (isset($view->status_msg['message'])) : $view->out( $view->status_msg['message'] ); endif; ?>
</div>
<?php endif;?>

<?php if ( isset($view->error_msg) && !isset($view->validation_errors)):?>
<div class="error">
    <?php if (isset($view->error_msg['headline'])) : ?><b><?php $view->out( $view->error_msg['headline'] ); ?>!</b><?php endif; ?>
    <?php if (isset($view->error_msg['message'])) : $view->out( $view->error_msg['message'] ); endif; ?>
</div>
<?php endif;?>

<?php if ( isset($view->validation_errors) ):?>
<div class="error">
    <span class="inline_h2">The form that you completed had some errors:</span>
    <ul>
        <?php foreach ($view->validation_errors as $validation_error): ?>
        <li>
            <?php if (isset($validation_error['headline'])) : ?><b><?php $view->out( $validation_error['headline'] ); ?>!</b><?php endif; ?>
            <?php 
	            
	            if (isset($validation_error['message'])) {
	            
	            	$view->out( $validation_error['message'] ); 
	             
	             } else {
		             // backwards compatabilitiy wth old style msgs used by validators
		             $view->out( $validation_error );
	             }
	        ?>
        </li>
        <?php endforeach;?>
    </ul>
</div>
<?php endif;?>