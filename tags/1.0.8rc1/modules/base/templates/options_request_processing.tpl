<h2><?=$headline?></h2>

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
    
     <fieldset name="owa-geolocation-options" class="options">
	<legend>Geo-location Options</legend>
	
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
    
	</fieldset>
	
	<BUTTON type="submit" name="<?=$this->getNs();?>action" value="base.optionsUpdate">Update Configuration</BUTTON>
	<BUTTON type="submit" name="<?=$this->getNs();?>action" value="base.optionsReset">Reset to Default Values</BUTTON>
</form>