<div id="report_top_level_nav">
	<?=$this->makeTwoLevelNav($top_level_report_nav, $sub_nav);?>
</div>

<!-- needed to clear the nav -->
<div class="post-nav"></div>

<div class="wrap">

	<fieldset>
		<legend>Report Filters</legend>
		<? include('report_period_filters.tpl');?> 
	</fieldset>
	
	<?=$subview;?>

</div>