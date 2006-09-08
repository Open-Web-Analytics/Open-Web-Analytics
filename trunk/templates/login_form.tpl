

<h1><?=$page_h1;?></h1>

<DIV class="status_msg">
	<?=$status_msg;?>
</DIV>

<form method="POST" action="<?=$_SERVER['PHP_SELF'];?>">
    
    <fieldset>
    	<legend>Login</legend>
    	<TABLE border ="1">
		<TR>
			<TH scope="row">User Name</TH>
			<TD><INPUT type="text" size="30" name="user_id"></TD>
		</TR>
	
		<TR>
			<TH scope="row">Password</TH>
			<TD><INPUT type="password" size="30" name="password"></TD>
		</TR>
		
		<TR>
			<TH scope="row"></TH>
			<TD>
			<input type="text" size="70" name="go" value="<?=$go?>">
			<input name="action" value="auth" type="hidden">
			<INPUT type="submit" size="30" name="submit" value="Save"></TD>
		</TR>
		
		<TR>
			<TD>
				<a href="<?=$_SERVER['PHP_SELF'].'?page=request_new_password';?>">Forgot your password?</a>
			</TD>
		</TR>
    	
    	</TABLE>
    
    </fieldset>
    
</form>
 