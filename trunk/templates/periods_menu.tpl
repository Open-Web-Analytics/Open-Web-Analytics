<TABLE>
	<TR>
		<? if (count($sites) > 1):?>
		<TH>Site</TH>
		<? endif;?>
		<TH>Time Period</TH>
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
				<? foreach ($reporting_periods as $reporting_period => $value):?>
					<OPTION VALUE="<?=$reporting_period;?>" <?php if ($params['period'] == $reporting_period): echo 'selected'; endif; ?>><?=$value['label'];?></OPTION>
				<?endforeach;?>
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
	
