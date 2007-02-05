<div class="top_level_nav">
	
	<?=$this->makeNavigation($top_level_report_nav);?>

</div>
<P>

<fieldset>
	<legend>Report Filters</legend>
	<? include('report_period_filters.tpl');?> 
</fieldset>

<?=$subview;?>