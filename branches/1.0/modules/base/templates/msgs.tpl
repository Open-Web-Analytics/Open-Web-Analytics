<?php if(!empty($status_msg)):?>
<DIV class="status"><?php echo $status_msg;?></div>
<?php endif;?>

<?php if (isset($error_msg)):?>
<DIV class="error"><?php echo $error_msg;?></DIV>
<?php endif;?>