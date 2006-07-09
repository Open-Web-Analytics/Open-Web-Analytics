<TABLE>
	<TR>
	
		<TH>Site</TH>
		<TH>Reporting Period</TH>
		
	</TR>
	<TR>
		<TD valign="top">
			<form action="<?=$this->make_report_link('dashboard_report.php');?>" method="GET">
				<SELECT name="sites" onchange='OnChange(this.form.sites, "site_id");' <? if (count($sites) == 1):?>DISABLED<?endif;?>>
				
				<?foreach ($sites as $site => $value):?>
					<OPTION VALUE="<?=$value['site_id'];?>" <?php if ($params['site_id'] == $value['site_id']): echo 'selected'; endif; ?>><?=$value['name'];?></OPTION>
				<?endforeach;?>
					<OPTION VALUE="" <?php if (empty($params['site_id'])): echo 'selected'; endif; ?>>All Sites</OPTION>
				
				</SELECT>
			</FORM>
		</TD>
		
		<TD valign="top">
			<TABLE>
				<TR>
					<TD>
						<input type="radio" name="period_type" id="set_periods" onclick='choosePeriodType("set_periods_form");' <? if (array_key_exists($params['period'], $reporting_periods)):?>CHECKED<?endif;?>>
					</TD>
					<TH>Time Period: </th>
					<TD><form action="" method="GET" name="set_periods_form">
							<SELECT name="period" onchange='OnChange(this.form.period, "period");' <? if (!array_key_exists($params['period'], $reporting_periods)):?>DISABLED<?endif;?>>
							<? foreach ($reporting_periods as $reporting_period => $value):?>
								<OPTION VALUE="<?=$reporting_period;?>" <?php if ($params['period'] == $reporting_period): echo 'selected'; endif; ?>><?=$value['label'];?></OPTION>
							<?endforeach;?>
							</SELECT>
						</FORM>		
					</TD>
				</TR>
				<TR>
					
					<td>
						<input type="radio" name="period_type" id="date_periods" onclick='choosePeriodType("date_periods_form");' <? if (array_key_exists($params['period'], $date_reporting_periods)):?>CHECKED<?endif;?>>
					</TD>
					<Th>Date Period:</th>
					<TD>
						<form action="" method="GET" name="date_periods_form" >
							<SELECT name="period" onchange='dateFormReveal(this.form.period);' <? if (!array_key_exists($params['period'], $date_reporting_periods)):?>DISABLED<?endif;?>>
							<? foreach ($date_reporting_periods as $date_reporting_period => $value):?>
								<OPTION VALUE="<?=$date_reporting_period;?>" <?php if ($params['period'] == $date_reporting_period): echo 'selected'; endif; ?>><?=$value['label'];?></OPTION>
							<?endforeach;?>
							</SELECT>
						</FORM>	
					</TD>
				</TR>
			</TABLE>
			
		</TD>
		
		<TD valign="top">
		
			<div id="day_container" class="<?if ($params['period'] != 'day'): echo 'invisible'; endif;?>">
				<table>
				<form action="" method="GET" name="day" id="day">
					<TR>
						<TH>Month</TH>
						<TH>Day</TH>
						<TH>Year</TH>
					</TR>
					<TR>
						<TD>
						<SELECT name="month">
							<? foreach ($months as $month => $value):?>
								<OPTION VALUE="<?=$month;?>" <?php if ($params['month'] == $month): echo 'selected'; endif; ?>><?=$value['label'];?></OPTION>
							<?endforeach;?>
						</SELECT>
						</TD>
						<TD>
							<SELECT name="day">
							<? foreach ($days as $day):?>
								<OPTION VALUE="<?=$day;?>" <?php if ($params['day'] == $day): echo 'selected'; endif; ?>><?=$day;?></OPTION>
							<?endforeach;?>
							</SELECT>
						
						</TD>
						<TD>
							<SELECT name="year">
							<? foreach ($years as $year):?>
								<OPTION VALUE="<?=$year;?>" <?php if ($params['year'] == $year): echo 'selected'; endif; ?>><?=$year;?></OPTION>
							<?endforeach;?>
							</SELECT>
						</TD>
						<TD><input type="hidden" name="period" value="day"><input type="button" name="date_submit" value="Go" onclick='changeDate("day");'></TD>
					</TR>
				</form>
				</table>
			</div>
			
			<div id="month_container" class="<?if ($params['period'] != 'month'): echo 'invisible'; endif;?>">
				<table>
				<form action="" method="GET" name="month" id="month">
					<TR>
						<TH>Month</TH>
						<TH>Year</TH>
					</TR>
					<TR>
						<TD>
							<SELECT name="month">
							<? foreach ($months as $month => $value):?>
								<OPTION VALUE="<?=$month;?>" <?php if ($params['month'] == $month): echo 'selected'; endif; ?>><?=$value['label'];?></OPTION>
							<?endforeach;?>
							</SELECT>
						</TD>
						<TD>
							<SELECT name="year">
							<? foreach ($years as $year):?>
								<OPTION VALUE="<?=$year;?>" <?php if ($params['year'] == $year): echo 'selected'; endif; ?>><?=$year;?></OPTION>
							<?endforeach;?>
							</SELECT>
						</TD>
						<TD><input type="hidden" name="period" value="month"><input type="button" name="date_submit" value="Go" onclick='changeDate("month");'></TD>
					</TR>
				</form>
				</table>
			</div>
			
			<div id="year_container" class="<?if ($params['period'] != 'year'): echo 'invisible'; endif;?>">
				<table>
				<form action="" method="GET" name="" id="year">
					<TR>
						<TH>Year</TH>
					</TR>
					<TR>
						<TD>
							<SELECT name="year">
							<? foreach ($years as $year):?>
								<OPTION VALUE="<?=$year;?>" <?php if ($params['year'] == $year): echo 'selected'; endif; ?>><?=$year;?></OPTION>
							<?endforeach;?>
							</SELECT>
						</TD>
						<TD><input type="hidden" name="period" value="year"><input type="button" name="date_submit" value="Go" onclick='changeDate("year");'></TD>
					</TR>
				</form>
				</table>
			</div>
			
			<div id="date_range_container" class="<?if ($params['period'] != 'date_range'): echo 'invisible'; endif;?>">
				<table>
				<form action="" method="GET" name="" id="date_range">
					
				<TR>
						<TH>Start Month</TH>
						<TH>Start Day</TH>
						<TH>Start Year</TH>
						<TH></TH>
						<TH>End Month</TH>
						<TH>End Day</TH>
						<TH>End Year</TH>
					</TR>
					<TR>
						<TD>
						<SELECT name="month">
							<? foreach ($months as $month => $value):?>
								<OPTION VALUE="<?=$month;?>" <?php if ($params['month'] == $month): echo 'selected'; endif; ?>><?=$value['label'];?></OPTION>
							<?endforeach;?>
						</SELECT>
						</TD>
						<TD>
							<SELECT name="day">
							<? foreach ($days as $day):?>
								<OPTION VALUE="<?=$day;?>" <?php if ($params['day'] == $day): echo 'selected'; endif; ?>><?=$day;?></OPTION>
							<?endforeach;?>
							</SELECT>
						
						</TD>
						<TD>
							<SELECT name="year">
							<? foreach ($years as $year):?>
								<OPTION VALUE="<?=$year;?>" <?php if ($params['year'] == $year): echo 'selected'; endif; ?>><?=$year;?></OPTION>
							<?endforeach;?>
							</SELECT>
						</TD>
					
						<TD> to </TD>

						<TD>
							<SELECT name="month2">
							<? foreach ($months as $month => $value):?>
								<OPTION VALUE="<?=$month;?>" <?php if ($params['month2'] == $month): echo 'selected'; endif; ?>><?=$value['label'];?></OPTION>
							<?endforeach;?>
							</SELECT>
						</TD>
						<TD>
							<SELECT name="day2">
							<? foreach ($days as $day):?>
								<OPTION VALUE="<?=$day;?>" <?php if ($params['day2'] == $day): echo 'selected'; endif; ?>><?=$day;?></OPTION>
							<?endforeach;?>
							</SELECT>
						
						</TD>
						<TD>
							<SELECT name="year2">
							<? foreach ($years as $year):?>
								<OPTION VALUE="<?=$year;?>" <?php if ($params['year2'] == $year): echo 'selected'; endif; ?>><?=$year;?></OPTION>
							<?endforeach;?>
							</SELECT>
						</TD>
						
						
						<TD><input type="hidden" name="period" value="date_range"><input type="button" name="date_submit" value="Go" onclick='changeDate("date_range");'></TD>
					</TR>
				
				
				</form>
				</table>
			</div>
			
		</TD>
		
	</TR>
</TABLE>
<?//print_r($params);?>
<SCRIPT>
<!--

var params = new Object()
<? foreach ($params as $k => $v):?>
	params["<?=$k;?>"] = "<?=$v;?>";
<? endforeach;?>

var baseURL  =  '<?=$this->make_report_link($report_name, null, false);?>'

function OnChange(dropdown, change_param) {

	var getParam = change_param
	var myindex  = dropdown.selectedIndex
	var SelValue = dropdown.options[myindex].value
	
	params[getParam] = SelValue;
	
	var get_string = ""
	
	for(param in params) {  // print out the params
  		get_string = get_string + param + "=" + params[param] + "&";
	}
	
	
	top.location.href = baseURL + get_string;
    
	return true;
}

function changeDate(form_name) {
	
	var f = document.getElementById(form_name);
	
	if (form_name == 'day') {
		params["month"] = f.month.value;
		params["day"] = f.day.value;
		params["year"] = f.year.value;		
	}
	
	if (form_name == 'month') {
		params["month"] = f.month.value;
		params["year"] = f.year.value;		
	}
	
	if (form_name == 'year') {
		params["year"] = f.year.value;		
	}
	
	if (form_name == 'date_range') {
		params["month"] = f.month.value;
		params["day"] = f.day.value;
		params["year"] = f.year.value;
		params["month2"] = f.month2.value;
		params["day2"] = f.day2.value;
		params["year2"] = f.year2.value;		
	}

	params["period"] = f.period.value;
	
	var get_string = ""
	
	for(param in params) {  // print out the params
  		get_string = get_string + param + "=" + params[param] + "&";
	}
	
	top.location.href = baseURL + get_string;
    
	return true;
	
}

function dateFormReveal(element_name) {
	
	var div_container = element_name.value + '_container'
	
	document.getElementById('day_container').className = "invisible";
	document.getElementById('month_container').className = "invisible";
	document.getElementById('year_container').className = "invisible";
	document.getElementById('date_range_container').className = "invisible";
	
	document.getElementById(div_container).className = "visible";
		
	return true;
}

function choosePeriodType(form_name) {
	
	
	document.set_periods_form.period.disabled = true;
	document.date_periods_form.period.disabled = true;	
	document.forms[form_name].period.disabled = false;
	
	if (form_name == 'date_periods_form') {
		
		element_name = document.forms[form_name].period;
		
		dateFormReveal(element_name);
		
	}
	
	if (form_name == 'set_periods_form') {
		
		element_name = document.forms[form_name].period;
		
		document.getElementById('day_container').className = "invisible";
		document.getElementById('month_container').className = "invisible";
		document.getElementById('year_container').className = "invisible";
		document.getElementById('date_range_container').className = "invisible";
		
		//dateFormReveal(element_name);
		
	}
	
	
	return true;
}

//-->
</SCRIPT>
	
