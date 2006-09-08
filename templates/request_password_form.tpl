

<h1><?=$page_h1;?></h1>

<DIV class="status_msg">
	<?=$status_msg;?>
</DIV>

<form method="POST" action="<?=$_SERVER['PHP_SELF'];?>">
    
    <fieldset>
    	<legend>Login</legend>
    	<TABLE border ="1">
		<TR>
			<TH scope="row">E-mail Address</TH>
			<TD><INPUT type="text" size="30" name="email_address" value=""></TD>
		</TR>
		
		<TR>
			<TH scope="row"></TH>
			<TD>
		
			<input name="action" value="request_new_password" type="hidden">
			<INPUT type="submit" size="30" name="submit" value="Save"></TD>
		</TR>
    	
    	</TABLE>
    
    </fieldset>
    
</form>
 