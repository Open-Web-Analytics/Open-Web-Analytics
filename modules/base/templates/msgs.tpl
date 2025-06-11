<?php if( ! empty( $status_msg ) ):?>
<div class="status">
    <?php if (isset($status_msg['headline'])) : ?><b><?php $this->out( $status_msg['headline'] ); ?>!</b><?php endif; ?>
    <?php if (isset($status_msg['message'])) : $this->out( $status_msg['message'] ); endif; ?>
</div>
<?php endif;?>

<?php if ( isset($error_msg) && !isset($validation_errors)):?>
<div class="error">
    <?php if (isset($error_msg['headline'])) : ?><b><?php $this->out( $error_msg['headline'] ); ?>!</b><?php endif; ?>
    <?php if (isset($error_msg['message'])) : $this->out( $error_msg['message'] ); endif; ?>
</div>
<?php endif;?>

<?php if ( isset($validation_errors) ):?>
<div class="error">
    <span class="inline_h2">The form that you completed had some errors:</span>
    <ul>
        <?php foreach ($validation_errors as $validation_error): ?>
        <li>
            <?php if (isset($validation_error['headline'])) : ?><b><?php $this->out( $validation_error['headline'] ); ?>!</b><?php endif; ?>
            <?php 
	            
	            if (isset($validation_error['message'])) {
	            
	            	$this->out( $validation_error['message'] ); 
	             
	             } else {
		             // backwards compatabilitiy wth old style msgs used by validators
		             $this->out( $validation_error );
	             }
	        ?>
        </li>
        <?php endforeach;?>
    </ul>
</div>
<?php endif;?>