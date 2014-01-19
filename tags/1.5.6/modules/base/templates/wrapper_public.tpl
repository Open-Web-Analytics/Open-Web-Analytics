<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1" />
		<title><?php echo $page_title;?> - Open Web Analytics</title>
		<?php include($this->getTemplatePath('base','head.tpl'));?>
	</head>
	
	<body>
	
		<div class="owa">
			<div id="header" style="text-align:center;">
				<table width="100%">
					<TR>
						<TD class="">
							<img src="<?php echo $this->makeImageLink('base/i/owa_logo_150w.jpg'); ?>" alt="Open Web Analytics"><BR>	
						</TD>
					</TR>
				</table>
			</div>
			<BR>
			<?php include($this->setTemplate('msgs.tpl'));?>
			<BR>
			<?php if (isset($content)) { echo $content; }?>
			<?php echo $body;?>
			
			<BR><BR><BR><BR>
			<div style="text-align:center">	
				<span class="inline_h4"><a href="http://www.openwebanalytics.com">Web Analytics</a> powered by <a href="http://www.openwebanalytics.com">Open Web Analytics</a> - v: <?php echo OWA_VERSION;?></span>
			</div>
		</div>
		
	</body>
	
</html>