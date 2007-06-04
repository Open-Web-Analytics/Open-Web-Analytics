<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1" />
		<title>Open Web Analytics - <?=$page_title;?></title>
	</head>
	
	<body>
	
	<? include('css.tpl');?>
	
	<DIV id="header">
		<table width="100%">
			<TR>
				<TD>
					<span class="inline_h1">Open Web Analytics - <?=OWA_VERSION;?></span>		
				</TD>
			
				<TD align="right">
					 <i>Open Source Web Analytics Framework.</i> 	
				</TD>
			</TR>
		</table>
	</div>
	
	
	
	<div class="wrap">
		
		
		<? include('msgs.tpl');?>
	<BR>
		<?=$content;?>
		<?=$body;?>

	</div>
	
	</body>
</html>