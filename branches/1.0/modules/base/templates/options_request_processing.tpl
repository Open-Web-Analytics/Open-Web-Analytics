<h2><?php echo $headline?></h2>

<form method="post">
    
    <fieldset name="owa-options" class="options">
	<legend>Request Processing Options</legend>
			
	<DIV class="setting">	
		Resolve Host Names: 
		<SELECT NAME="<?php echo $this->getNs();?>config[resolve_hosts]">
	
		<OPTION VALUE="0" <?php if ($config['resolve_hosts'] == false):?>SELECTED<?php endif; ?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <?php if ($config['resolve_hosts'] == true):?>SELECTED<?php endif; ?>>
		On</OPTION>
			
		</SELECT>
	</DIV> 
	
	<DIV class="setting">	
		Log Requests from Feed Readers: 
		<SELECT NAME="<?php echo $this->getNs();?>config[log_feedreaders]">
	
		<OPTION VALUE="0" <?php if ($config['log_feedreaders'] == false):?>SELECTED<?php endif; ?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <?php if ($config['log_feedreaders'] == true):?>SELECTED<?php endif; ?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	
	<DIV class="setting">	
		Log Requests from Known Robots: 
		<SELECT NAME="<?php echo $this->getNs();?>config[log_robots]">
	
		<OPTION VALUE="0" <?php if ($config['log_robots'] == false):?>SELECTED<?php endif; ?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <?php if ($config['log_robots'] == true):?>SELECTED<?php endif; ?>>
		On</OPTION>
			
		</SELECT>
	</DIV>	
	
	<DIV class="setting">	
		Announce New Visitors via E-mail: 
		<SELECT NAME="<?php echo $this->getNs();?>config[announce_visitors]">
	
		<OPTION VALUE="0" <?php if ($config['announce_visitors'] == false):?>SELECTED<?php endif; ?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <?php if ($config['announce_visitors'] == true):?>SELECTED<?php endif; ?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	
	<DIV class="setting">	

	Notice Email Address: <input type="text" name="<?php echo $this->getNs();?>config[notice_email]" value="<?php echo $config['notice_email']?>"><BR>
	
	</DIV>
	
    </fieldset>
    
     <fieldset name="owa-geolocation-options" class="options">
	<legend>Geo-location Options</legend>
	
	<DIV class="setting">	
	
		Perform Geo-location Lookup: 
		<SELECT NAME="<?php echo $this->getNs();?>config[geolocation_lookup]">
	
		<OPTION VALUE="0" <?php if ($config['geolocation_lookup'] == false):?>SELECTED<?php endif; ?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <?php if ($config['geolocation_lookup'] == true):?>SELECTED<?php endif; ?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	<DIV class="setting">	
		Geolocation Service: 
		<SELECT NAME="<?php echo $this->getNs();?>config[geolocation_service]">
	
		<OPTION VALUE="hostip" <?php if ($config['geolocation_service'] == 'hostip'):?>SELECTED<?php endif; ?>>
		Hostip.info Web Service (free)</OPTION>
			
		</SELECT>
	
	</DIV>
    
	</fieldset>
	
	<BUTTON type="submit" name="<?php echo $this->getNs();?>action" value="base.optionsUpdate">Update Configuration</BUTTON>
	<BUTTON type="submit" name="<?php echo $this->getNs();?>action" value="base.optionsReset">Reset to Default Values</BUTTON>
</form>