<TABLE>
	<TR>
		<? if (count($sites) > 1):?>
		<TH>Site</TH>
		<? endif;?>
		<TH>Period</TH>
	</TR>
	<TR>
		<? if (count($sites) > 1):?>
		<TD>
			<form action="<?=$this->make_report_link('dashboard_report.php');?>" method="GET">
				<SELECT name="sites" onchange='OnChange(this.form.sites, "site_id");'>
				
				<?foreach ($sites as $site => $value):?>
					<OPTION VALUE="<?=$value['site_id'];?>" <?php if ($params['site_id'] == $value['site_id']): echo 'selected'; endif; ?>><?=$value['name'];?></OPTION>
				<?endforeach;?>
					<OPTION VALUE="" <?php if (empty($params['site_id'])): echo 'selected'; endif; ?>>All Sites</OPTION>
				
				</SELECT>
			</FORM>
		</TD>
		<?endif;?>
		
		<TD>
			<form action="<?=$this->make_report_link('dashboard_report.php');?>" method="GET">
				<SELECT name="period" onchange='OnChange(this.form.period, "period");'>
					<OPTION VALUE="today" <?php if ($params['period'] == 'today'): echo 'selected'; endif; ?>>Today</OPTION>
					<OPTION VALUE="yesterday" <?php if ($params['period'] == 'yesterday'): echo 'selected'; endif; ?>>Yesterday</OPTION>
					<OPTION VALUE="this_week" <?php if ($params['period'] == 'this_week'): echo 'selected'; endif; ?>>This Week</OPTION>
					<OPTION VALUE="last_seven_days" <?php if ($params['period'] == 'last_seven_days'): echo 'selected'; endif; ?>>Last Seven Days</OPTION>
					<OPTION VALUE="this_month" <?php if ($params['period'] == 'this_month'): echo 'selected'; endif;?>>This Month</OPTION>
					<OPTION VALUE="this_year" <?php if ($params['period'] == 'this_year'): echo 'selected'; endif;?>>This Year</OPTION>
				</SELECT>
			</FORM>		
		</TD>
	</TR>
</TABLE>

<SCRIPT>
<!--
function OnChange(dropdown, change_param)
{
	var params = new Object()
<? foreach ($params as $k => $v):?>
	params["<?=$k;?>"] = "<?=$v;?>";
<? endforeach;?>
	
	var getParam = change_param
	var myindex  = dropdown.selectedIndex
	var SelValue = dropdown.options[myindex].value
	
	params[getParam] = SelValue;
	
	var get_string = ""
	
	for(param in params) {  // print out the params
  		get_string = get_string + param + "=" + params[param] + "&";
	}
	
	var baseURL  =  '<?=$this->make_report_link($report_name);?>'
	
	top.location.href = baseURL + get_string;
    
	return true;
}
//-->
</SCRIPT>
	
