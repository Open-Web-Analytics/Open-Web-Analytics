<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml">

	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1" />
		<title>Open Web Analytics - <?php echo $page_title;?></title>
		<?php include($this->setTemplate('head.tpl'));?>
		<?php include($this->setTemplate('css.tpl'));?>
	</head>
	
	<body>
	
		<?php include($this->setTemplate('header.tpl'));?>
		
		<?php include($this->setTemplate('msgs.tpl'));?>
			
		<?php echo $body;?>
	
	</body>
	
</html>