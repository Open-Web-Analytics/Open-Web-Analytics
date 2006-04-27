<div class=wrap>
  <form method="post">
    <h2><?=$page_title?></h2>

    <fieldset name="owa-options" class="options">
	<legend>Request Processing Options</legend>
			
	<DIV class="setting">	
		Resolve Host Names: 
		<SELECT NAME="resolve_hosts">
	
		<OPTION VALUE="0" <? if ($config['resolve_hosts'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['resolve_hosts'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV> 
	
	<DIV class="setting">	
		Log Requests from Feed Readers: 
		<SELECT NAME="log_feedreaders">
	
		<OPTION VALUE="0" <? if ($config['log_feedreaders'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['log_feedreaders'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	
	<DIV class="setting">	
		Log Requests from Known Robots: 
		<SELECT NAME="log_robots">
	
		<OPTION VALUE="0" <? if ($config['log_robots'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['log_robots'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>	
	
    </fieldset>
     
    <fieldset name="owa-db-options" class="options">
	<legend>Database Options</legend>
	
	<DIV class="setting">	
		Fetch Configuration from Database 
		<SELECT NAME="fetch_config_from_db">
	
		<OPTION VALUE="0" <? if ($config['fetch_config_from_db'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['fetch_config_from_db'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	
	<DIV class="setting">	
		Asynchronous Database Mode: 
		<SELECT NAME="async_db">
	
		<OPTION VALUE="0" <? if ($config['async_db'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['async_db'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	
	<DIV class="setting">	

	Log File Directory: <input type="text" name="async_log_dir" value="<?=$config['async_log_dir']?>"><BR>
	
	</DIV>
	
	<DIV class="setting">	

	Event Log File Name: <input type="text" name="async_log_file" value="<?=$config['async_log_file']?>"><BR>
	
	</DIV>
	
    </fieldset>
    
    <fieldset name="owa-error-options" class="options">
	<legend>Error Logging</legend>
	
	<DIV class="setting">	

	Notice Email Address: <input type="text" name="notice_email" value="<?=$config['notice_email']?>"><BR>
	
	</DIV>
    
	</fieldset>
	
    <fieldset name="owa-geolocation-options" class="options">
	<legend>Geolocation Options</legend>
	
	<DIV class="setting">	
	
		Perform Geo-location Lookup: 
		<SELECT NAME="geolocation_lookup">
	
		<OPTION VALUE="0" <? if ($config['geolocation_lookup'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['geolocation_lookup'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	<DIV class="setting">	
		Geolocation Service: 
		<SELECT NAME="geolocation_service">
	
		<OPTION VALUE="hostip" <? if ($config['geolocation_service'] == 'hostip'):?>SELECTED<?endif;?>>
		Hostip.info Webservice</OPTION>
			
		</SELECT>
	
	</DIV>
    
	</fieldset>
	
	<fieldset name="owa-reports-options" class="options">
	<legend>Reporting</legend>
	
	<DIV class="setting">	

	Reporting Wrapper: <input type="text" name="report_wrapper" value="<?=$config['report_wrapper']?>"><BR>
	
	</DIV>
    
	</fieldset>
	
	<fieldset name="owa-error-options" class="options">
	<legend>Error Reporting</legend>
	
	<DIV class="setting">	
		Erorr Reporting Mode: 
		<SELECT NAME="error_handler">
	
		<OPTION VALUE="production" <? if ($config['error_handler'] == 'production'):?>SELECTED<?endif;?>>
		Production (errors logged to file with critcal alerts send via email)</OPTION>
		<OPTION VALUE="development" <? if ($config['error_handler'] == 'development'):?>SELECTED<?endif;?>>
		Development (errors logged to file and seperate browser window)</OPTION>
		</SELECT>
	
	</DIV>
	
	</fieldset>
	
     
     
<BR>
   <input type="submit" name="wa_update_options" value="Save Options" />
   <input type="submit" name="wa_reset_options" value="Reset Options to Default" />
  </form>
 </div>
 