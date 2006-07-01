<? include('css.tpl');?>	

<div class="wrap">
	
	<?if ($page_type == 'report'):?>
	<? include_once('nav.tpl');?>
	<?endif;?>
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
        
	<?=$content; ?>
				
</div>
