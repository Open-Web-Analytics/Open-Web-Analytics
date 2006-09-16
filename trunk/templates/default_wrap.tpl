<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">

	<head>
		<meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1" />
		<title>Open Web Analytics - <?=$page_title;?></title>
	</head>
	
	<body>
	
	<? include('css.tpl');?>
	
	<style>
	body {background-color:#cccccc;}
	.wrap {margin:10px 40px 20px 40px; background-color:#ffffff; border:1px solid #000000; padding:8px;}

	</style>
		
	<DIV class="owa_banner">
		<table width="100%">
			<TR>
				<TD>Open Web Analytics</TD>
				<TD align="right">
					<? if ($this->getAuthStatus() == true):?>
					Logout
					<?else:?>
					Login
					<?endif;?>
				</TD>
			</TR>
		</table>
		
	
	</DIV>

	<div class="wrap">
	
		<? include_once('nav.tpl');?>
		
		<? if ($news):?>
		<fieldset class="options">
			<legend>OWA news & Announcements</legend>
			<? include_once('news.tpl');?>
		</fieldset>
		<? endif;?>
		
		<?if ($page_type == 'report'):?>
		<fieldset class="options">
			<legend>Report Filters</legend>
			<? include('periods_menu.tpl');?>	
		</fieldset>
		<? endif;?>
	</div>

	<div class="wrap">
		<?=$content;?>
	</div>
	
	</body>
</html>