<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml">

	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1" />
		<title>Open Web Analytics - <?=$page_title;?></title>
		<?php $this->includeTemplate('css.tpl');?>
	</head>
	
	<body>
	
		<?php $this->includeTemplate('header.tpl');?>
		
		<?php $this->includeTemplate('msgs.tpl');?>
			
		<?=$body;?>
	
	</body>
	
</html>