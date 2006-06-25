<? include('css.tpl');?>	

<div class="wrap">
<? include_once('nav.tpl');?>

	<fieldset class="options">
		<legend>Report Filters</legend>
		<? include('periods_menu.tpl');?>	
	</fieldset>
</div>

<div class="wrap">
        
	<?=$content; ?>
				
</div>
