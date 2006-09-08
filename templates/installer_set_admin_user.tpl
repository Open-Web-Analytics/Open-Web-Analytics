

<h1><?=$page_h1;?></h1>

<DIV class="status_msg">
	<?=$status_msg;?>
</DIV>

<form method="GET" action="<?=$_SERVER['PHP_SELF'];?>">
    
    <fieldset>
    	<legend>Set your the Admin User Profile for your Installation</legend>
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
			<TH scope="row">Password</TH>
			<TD><INPUT type="password" size="30" name="password2"></TD>
		</TR>
		
		<TR>
			<TH scope="row">Real Name</TH>
			<TD><INPUT type="text" size="30" name="real_name"></TD>
		</TR>
		
		<TR>
			<TH scope="row">Email Address</TH>
			<TD><INPUT type="text" size="30" name="email_address"></TD>
		</TR>
		
		<TR>
			<TH scope="row"></TH>
			<TD>
			<input type="hidden" name="action" value="set_admin_user">
			<INPUT type="submit" size="30" name="submit" value="Save"></TD>
		</TR>
    	
    	</TABLE>
    
    </fieldset>
    
</form>
 