<div class="panel_headline"><?=$headline?></div>
<div class="subview_content">
<form method="post">

	 <fieldset name="owa-options" class="options">
	<legend>Request Processing Options</legend>
			
	<DIV class="setting">	
		Resolve Host Names: 
		<SELECT NAME="<?=$this->getNs();?>config[resolve_hosts]">
	
		<OPTION VALUE="0" <? if ($config['resolve_hosts'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['resolve_hosts'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV> 
	
	<DIV class="setting">	
		Log Requests from Feed Readers: 
		<SELECT NAME="<?=$this->getNs();?>config[log_feedreaders]">
	
		<OPTION VALUE="0" <? if ($config['log_feedreaders'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['log_feedreaders'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	
	<DIV class="setting">	
		Log Requests from Known Robots: 
		<SELECT NAME="<?=$this->getNs();?>config[log_robots]">
	
		<OPTION VALUE="0" <? if ($config['log_robots'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['log_robots'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>	
	
	<DIV class="setting">	
		Announce New Visitors via E-mail: 
		<SELECT NAME="<?=$this->getNs();?>config[announce_visitors]">
	
		<OPTION VALUE="0" <? if ($config['announce_visitors'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['announce_visitors'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	
	<DIV class="setting">	

	Notice Email Address: <input type="text" name="<?=$this->getNs();?>config[notice_email]" value="<?=$config['notice_email']?>"><BR>

	</DIV>
	
    </fieldset>
    
    <BR>
    
     <fieldset name="owa-geolocation-options" class="options">
	<legend>Geo-location</legend>
	
	<DIV class="setting">	
	
		Perform Geo-location Lookup: 
		<SELECT NAME="<?=$this->getNs();?>config[geolocation_lookup]">
	
		<OPTION VALUE="0" <? if ($config['geolocation_lookup'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['geolocation_lookup'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	<DIV class="setting">	
		Geolocation Service: 
		<SELECT NAME="<?=$this->getNs();?>config[geolocation_service]">
	
		<OPTION VALUE="hostip" <? if ($config['geolocation_service'] == 'hostip'):?>SELECTED<?endif;?>>
		Hostip.info Web Service (free)</OPTION>
			
		</SELECT>
	
	</DIV>
	
	<div class="setting">
	
		Google Maps API Key: <input type="text" size="90" name="<?=$this->getNs();?>config[google_maps_api_key]" value="<?=$config['google_maps_api_key']?>"><BR>
	
		
	</div>
    
	</fieldset>
		
	
	<BR>

    <fieldset name="owa-event-options" class="options">
	<legend>Event Handling</legend>
	
	<DIV class="setting">	
		Asynchronous Event Handling Mode: 
		<SELECT NAME="<?=$this->getNs();?>config[async_db]">
	
		<OPTION VALUE="0" <? if ($config['async_db'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['async_db'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	
	<DIV class="setting">	

		Event Log File Directory: <input type="text" size="80" name="<?=$this->getNs();?>config[async_log_dir]" value="<?=$config['async_log_dir']?>"><BR>
	
	</DIV>
	
	<DIV class="setting">	

		Event Log File Name: <input type="text" name="<?=$this->getNs();?>config[async_log_file]" value="<?=$config['async_log_file']?>"><BR>
	
	</DIV>
	
    </fieldset>
	
    <BR>
    
    <fieldset name="owa-error-options" class="options">
	<legend>Error Logging</legend>
	
	<DIV class="setting">	
		Logging Mode: 
		<SELECT NAME="<?=$this->getNs();?>config[error_handler]">
	
		<OPTION VALUE="production" <? if ($config['error_handler'] == 'production'):?>SELECTED<?endif;?>>
		Production (Errors logged to file)</OPTION>
		<OPTION VALUE="development" <? if ($config['error_handler'] == 'development'):?>SELECTED<?endif;?>>
		Development (Debug and Error messages logged to file)</OPTION>
		</SELECT>
	
	</DIV>
	
	</fieldset>
	
	<BR>
	<? if ($this->config['is_embedded'] == false):?>
	<fieldset name="owa-reports-options" class="options">
		<legend>Reporting</legend>
		
		<DIV class="setting">	
	
			Reporting Wrapper: <input type="text" name="<?=$this->getNs();?>config[report_wrapper]" value="<?=$config['report_wrapper']?>"><BR>
		
		</DIV>
    
	</fieldset>
    
	<BR>
	
	<fieldset name="owa-click-options" class="options">
	<legend>Click Tracking</legend>
	
	<DIV class="setting">	
		Click Drawing Mode:
		<SELECT NAME="<?=$this->getNs();?>config[click_drawing_mode]">
	
		<OPTION VALUE="center_on_page" <? if ($config['click_drawing_mode'] == 'center_on_page'):?>SELECTED<?endif;?>>
		Content centered</OPTION>
		<OPTION VALUE="expandable" <? if ($config['click_drawing_mode'] == 'expandable'):?>SELECTED<?endif;?>>
		Content resizable</OPTION>
		</SELECT>
	
	</DIV>
	
	</fieldset>
	
	<BR>
	
	
	<?endif;?>
	
	
	<BUTTON type="submit" name="<?=$this->getNs();?>action" value="base.optionsUpdate">Update Configuration</BUTTON>
	<BUTTON type="submit" name="<?=$this->getNs();?>action" value="base.optionsReset">Reset to Default Values</BUTTON>
	
</form>
</div>