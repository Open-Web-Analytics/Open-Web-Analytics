<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php if( ! empty( $view->status_msg ) ):?>
<?php
    /*
     * A SUCCESS message goes away on its own.
     *
     * It says something already happened, so it needs no acknowledging -- and
     * left on the page it becomes furniture that says "saved" long after the
     * next thing was done. Errors are NOT faded below: those describe something
     * still wrong, and the reader decides when they are finished with them.
     */
?>
<div class="status owa_transientStatus" role="status">
    <?php if (isset($view->status_msg['headline'])) : ?><b><?php $view->out( $view->status_msg['headline'] ); ?>!</b><?php endif; ?>
    <?php if (isset($view->status_msg['message'])) : $view->out( $view->status_msg['message'] ); endif; ?>
</div>
<script>
jQuery( function () {
    // Long enough to read a sentence, then a fade rather than a disappearance
    // -- something vanishing on its own reads as a glitch.
    setTimeout( function () {
        jQuery( '.owa_transientStatus' ).fadeOut( 600 );
    }, 6000 );
} );
</script>
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