

<h1><?=$page_h1;?></h1>

<DIV class="status_msg">
	<?=$status_msg;?>
</DIV>

<form method="POST" action="<?=$_SERVER['PHP_SELF'];?>">
    
    <fieldset>
    	<legend>Login</legend>
    	<TABLE border ="1">
		
	
		<TR>
			<TH scope="row">New Password</TH>
			<TD><INPUT type="password" size="30" name="password"></TD>
		</TR>
		
		<TR>
			<TH scope="row">Re-type your Password</TH>
			<TD><INPUT type="password" size="30" name="password"></TD>
		</TR>
		
		<TR>
			<TH scope="row"></TH>
			<TD>
			<input type="hidden" name="k" value="<?=$key;?>">
			<input name="action" value="reset_password" type="hidden">
			<INPUT type="submit" size="30" name="submit" value="Save"></TD>
		</TR>
    	
    	</TABLE>
    
    </fieldset>
    
</form>
 