<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1" />
        <title>Open Web Analytics - <?php echo $page_title;?></title>
        <?php include($this->setTemplate('head.tpl'));?>
    </head>

    <body>
        <!-- <div class="host_app_nav"><img src="<?php echo $this->makeImageLink('mediawiki_icon_50h.jpg');?>" align="absmiddle"> <a href="index.php?title=Special:SpecialPages">Return to your MediaWiki >></a></div> -->
        <div id="header"><?php include($this->setTemplate('header.tpl'));?></div>
        <?php include($this->setTemplate('msgs.tpl'));?>
        <?php echo $body;?>
        <!-- <div class="host_app_nav"><img src="<?php echo $this->makeImageLink('mediawiki_icon_50h.jpg');?>" align="absmiddle"> <a href="index.php?title=Special:SpecialPages">Return to your MediaWiki >></a></div> -->

        <?php include($this->getTemplatePath('base', 'footer.php'));?>
    </body>
</html>