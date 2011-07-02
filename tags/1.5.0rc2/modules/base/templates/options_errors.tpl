<h2><?php echo $headline;?></h2>

<form method="post">	

	<fieldset name="owa-error-options" class="options">
	<legend>Error Logging</legend>
	
	<DIV class="setting">	
		Logging Mode: 
		<SELECT NAME="<?php echo $this->getNs();?>config[error_handler]">
	
		<OPTION VALUE="production" <?php if ($config['error_handler'] == 'production'):?>SELECTED<?php endif;?>>
		Production (Errors logged to file)</OPTION>
		<OPTION VALUE="development" <?php if ($config['error_handler'] == 'development'):?>SELECTED<?php endif;?>>
		Development (Debug and Error messages logged to file)</OPTION>
		</SELECT>
	
	</DIV>
	
	</fieldset>
	
	<BUTTON type="submit" name="<?php echo $this->getNs();?>action" value="base.optionsUpdate">Update Configuration</BUTTON>
	<BUTTON type="submit" name="<?php echo $this->getNs();?>action" value="base.optionsReset">Reset to Default Values</BUTTON>
</form>