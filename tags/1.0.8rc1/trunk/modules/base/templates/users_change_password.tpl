

<div class="headline"><?=$headline;?></div>

<div id="login">
	<form method="POST">
    
    <fieldset>
    	<legend>Password Setup</legend>
    	<TABLE>
		
	
		<TR>
			<TH scope="row">New Password</TH>
			<TD><INPUT type="password" size="30" name="<?=$this->getNs();?>password"></TD>
		</TR>
		
		<TR>
			<TH scope="row">Re-type your Password</TH>
			<TD><INPUT type="password" size="30" name="<?=$this->getNs();?>password2"></TD>
		</TR>
		
		<TR>
			<TH scope="row"></TH>
			<TD>
			<input type="hidden" name="<?=$this->getNs();?>k" value="<?=$key;?>">
			<input name="<?=$this->getNs();?>action" value="base.usersChangePassword" type="hidden">
			<INPUT type="submit" size="30" name="<?=$this->getNs();?>submit_btn" value="Save Your New Password"></TD>
		</TR>
    	
    	</TABLE>
    
    </fieldset>
    
	</form>
 </div>