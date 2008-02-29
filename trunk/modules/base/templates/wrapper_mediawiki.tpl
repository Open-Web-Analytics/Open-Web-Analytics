<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1" />
		<title>Open Web Analytics - <?=$page_title;?></title>
		<?php $this->includeTemplate('css.tpl');?>
	</head>
	
	<body>
		<div class="host_app_nav"><img src="<?=$this->makeImageLink('mediawiki_icon_50h.jpg');?>" align="absmiddle"> <a href="/index.php?title=Special:SpecialPages">Return to your MediaWiki >></a></div>
		<div id="header"><?include ('header.tpl');?></div>
		<?php $this->includeTemplate('msgs.tpl');?>
		<?=$body;?>
		<div class="host_app_nav"><img src="<?=$this->makeImageLink('mediawiki_icon_50h.jpg');?>" align="absmiddle"> <a href="/index.php?title=Special:SpecialPages">Return to your MediaWiki >></a></div>
	</body>
</html>