<form action="<?=$this->make_report_link('dashboard_report.php');?>" method="GET">
	<SELECT name="period" onchange='OnChange(this.form.period, "period");'>
		<OPTION VALUE="today" <?php if ($period == 'today'): echo 'selected'; endif; ?>>Today</OPTION>
		<OPTION VALUE="yesterday" <?php if ($period == 'yesterday'): echo 'selected'; endif; ?>>Yesterday</OPTION>
		<OPTION VALUE="this_week" <?php if ($period == 'this_week'): echo 'selected'; endif; ?>>This Week</OPTION>
		<OPTION VALUE="last_seven_days" <?php if ($period == 'last_seven_days'): echo 'selected'; endif; ?>>Last Seven Days</OPTION>
		<OPTION VALUE="this_month" <?php if ($period == 'this_month'): echo 'selected'; endif;?>>This Month</OPTION>
		<OPTION VALUE="this_year" <?php if ($period == 'this_year'): echo 'selected'; endif;?>>This Year</OPTION>
	</SELECT>
	
</FORM>
<? if (count($sites) > 1):?>
<form action="<?=$this->make_report_link('dashboard_report.php');?>" method="GET">
	<SELECT name="sites" onchange='OnChange(this.form.sites, "site_id");'>
	
	<?foreach ($sites as $site => $value):?>
		<OPTION VALUE="<?=$value['site_id'];?>" <?php if ($params['site_id'] == $value['site_id']): echo 'selected'; endif; ?>><?=$value['name'];?></OPTION>
	<?endforeach;?>
		<OPTION VALUE="" <?php if (empty($params['site_id'])): echo 'selected'; endif; ?>>All Sites</OPTION>
	
	</SELECT>
	
</FORM>
<?endif;?>

<SCRIPT>
<!--
function OnChange(dropdown, param)
{
	var getParam = param
	var myindex  = dropdown.selectedIndex
	var SelValue = dropdown.options[myindex].value
	var baseURL  =  '<?=$this->make_report_link('dashboard_report.php');?>'
	top.location.href = baseURL + getParam + '=' + SelValue;
    
	return true;
}
//-->
</SCRIPT>
	
