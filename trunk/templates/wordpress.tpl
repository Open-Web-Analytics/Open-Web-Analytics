<? include('css.tpl');?>	

<div class="wrap">
	
	<? include_once('nav.tpl');?>

	<fieldset class="options">
		<legend>OWA news & Announcements</legend>
		<? include_once('news.tpl');?>
	</fieldset>
	
<?if ($page_type == 'report'):?>
	<fieldset class="options">
		<legend>Report Filters</legend>
		<? include('periods_menu.tpl');?>	
	</fieldset>
<? endif;?>
</div>

<div class="wrap">
        
	<?=$content; ?>
				
</div>
