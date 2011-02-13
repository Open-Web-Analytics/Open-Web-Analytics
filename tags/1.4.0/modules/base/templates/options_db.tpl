<h2><?php echo $headline?></h2>

<form method="post">

    <fieldset name="owa-db-options" class="options">
	<legend>Database Options</legend>
	
	<DIV class="setting">	
		Asynchronous Event Handling Mode: 
		<SELECT NAME="async_db">
	
		<OPTION VALUE="0" <?php if ($config['async_db'] == false):?>SELECTED<?php endif;?>>
		Off</OPTION>
		
		<OPTION VALUE="1" <?php if ($config['async_db'] == true):?>SELECTED<?php endif;?>>
		On</OPTION>
			
		</SELECT>
	</DIV>
	
	<DIV class="setting">	

		Event Log File Directory: <input type="text" size="80" name="async_log_dir" value="<?php echo $config['async_log_dir']?>"><BR>
	
	</DIV>
	
	<DIV class="setting">	

		Event Log File Name: <input type="text" name="async_log_file" value="<?php echo $config['async_log_file']?>"><BR>
	
	</DIV>
	
    </fieldset>
	
	<BR>
	
	<BUTTON type="submit" name="<?php echo $this->getNs();?>action" value="base.optionsUpdate">Update Configuration</BUTTON>
	<BUTTON type="submit" name="<?php echo $this->getNs();?>action" value="base.optionsReset">Reset to Default Values</BUTTON>
	
</form>
 