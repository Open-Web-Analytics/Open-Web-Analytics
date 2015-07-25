<?php if( ! empty( $status_msg ) ):?>
<div class="status"><?php $this->out( $status_msg );?></div>
<?php endif;?>

<?php if ( isset($error_msg) ):?>
<div class="error"><?php $this->out( $error_msg );?></div>
<?php endif;?>