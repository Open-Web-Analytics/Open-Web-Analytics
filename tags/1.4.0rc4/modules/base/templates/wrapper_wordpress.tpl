<?php include($this->setTemplate('css.tpl'));?>	

<div class="owa">
	
<?php include($this->setTemplate('header.tpl'));?>

<?php include($this->setTemplate('msgs.tpl'));?>

<?php include($this->setTemplate('head.tpl'));?>

<?php echo $body;?>

<?php include($this->getTemplatePath('base', 'footer.php'));?>

</div>