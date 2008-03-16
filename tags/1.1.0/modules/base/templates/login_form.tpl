

<h1><?=$page_h1;?></h1>

<DIV id="login_box">
<form method="POST">
   
    <fieldset>
    	<legend>Login</legend>
    	<TABLE>
		<TR>
			<TH scope="row">User Name:</TH>
			<TD><INPUT type="text" size="30" name="<?=$this->getNs();?>user_id" value="<?=$user_id;?>"></TD>
		</TR>
	
		<TR>
			<TH scope="row">Password:</TH>
			<TD><INPUT type="password" size="20" name="<?=$this->getNs();?>password"></TD>
		</TR>
		
		<TR>
			<TH scope="row"></TH>
			<TD>
			<input type="hidden" size="70" name="<?=$this->getNs();?>go" value="<?=$go?>">
			<input name="<?=$this->getNs();?>action" value="base.login" type="hidden">
			<INPUT type="submit" name="<?=$this->getNs();?>submit_btn" value="Login"></TD>
		</TR>
		
		<TR>
			<TD></TD>
			<TD>
				<BR><span class="info_text"><a href="<?=$this->makeLink(array('view' => 'base.passwordResetRequest'))?>">Forgot your password?</a></span>
			</TD>
		</TR>
    	
    	</TABLE>
    
    </fieldset>
    
</form>
 
</DIV>