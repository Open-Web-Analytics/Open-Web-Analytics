<h2><?=$headline?></h2>

<form method="post">

    <fieldset name="owa-db-options" class="options">
	<legend>Database Options</legend>
	
	<!--<DIV class="setting">	
		Fetch Configuration from Database 
		<SELECT NAME="fetch_config_from_db">
	
		<OPTION VALUE="0" <? if ($config['fetch_config_from_db'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['fetch_config_from_db'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV> -->
	
	<DIV class="setting">	
		Asynchronous Event Handling Mode: 
		<SELECT NAME="async_db">
	
		<OPTION VALUE="0" <? if ($config['async_db'] == false):?>SELECTED<?endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <? if ($config['async_db'] == true):?>SELECTED<?endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	
	<DIV class="setting">	

		Event Log File Directory: <input type="text" size="80" name="async_log_dir" value="<?=$config['async_log_dir']?>"><BR>
	
	</DIV>
	
	<DIV class="setting">	

		Event Log File Name: <input type="text" name="async_log_file" value="<?=$config['async_log_file']?>"><BR>
	
	</DIV>
	
    </fieldset>
	
	<BR>
	
	<BUTTON type="submit" name="<?=$this->getNs();?>action" value="base.optionsUpdate">Update Configuration</BUTTON>
	<BUTTON type="submit" name="<?=$this->getNs();?>action" value="base.optionsReset">Reset to Default Values</BUTTON>
	
</form>
 