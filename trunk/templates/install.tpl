<h1><?=$page_h1;?></h1>
<form method="post" action="<?=$_SERVER['PHP_SELF'];?>">

    <fieldset name="auth" class="options">
  
	    <legend>Database Information</legend>
	    
	    <TABLE border="1">
	    	<TR>
	    		<TH>Database Name</TH>
	    		<TH>Database Host</TH>
	    		<TH>Database User</TH>
	    		<TH>Database Password</TH>
	    	</TR>
	    	<TR>
	    		<TD><?=$config['db_name'];?></TD>
	    		<TD><?=$config['db_host'];?></TD>
	    		<TD><?=$config['db_user'];?></TD>
	    		<TD><?=$config['db_password'];?></TD>
	    	</TR>
	    
	    </TABLE>
    
    </fieldset>
    
    <fieldset name="auth" class="options">
		
	    <legend>Authentication</legend>
		
		<P>Please enter the Admin user name and password that was set in your configuration file to authorize the installation.</p> 
		
		<DIV class="user_name">	
			User Name: <input type="text" name="user_name" value=""><BR>
			Password: <input type="text" name="password" value=""><BR>
		</DIV>
	
    </fieldset>
    
    <DIV class="centered_buttons">	
    	<input type="hidden" name="action" value="install" />
   		<input type="submit" name="install_but" value="Install OWA Now!" />
    </DIV>
    
</form>
 