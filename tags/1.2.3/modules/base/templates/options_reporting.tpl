<h2><?php echo $headline;?></h2>

<form method="post">

	<fieldset name="owa-reports-options" class="options">
		<legend>Reporting</legend>
		
		<DIV class="setting">	
	
			Reporting Wrapper: <input type="text" name="<?php echo $this->getNs();?>config[report_wrapper]" value="<?php echo $config['report_wrapper']?>"><BR>
		
		</DIV>
    
	</fieldset>
	
	<BUTTON type="submit" name="<?php echo $this->getNs();?>action" value="base.optionsUpdate">Update Configuration</BUTTON>
	<BUTTON type="submit" name="<?php echo $this->getNs();?>action" value="base.optionsReset">Reset to Default Values</BUTTON>
</form>