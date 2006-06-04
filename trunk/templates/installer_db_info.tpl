<h1><?=$page_h1;?></h1>

<form method="post" action="<?=$_SERVER['PHP_SELF'];?>">

    <fieldset name="auth" class="options">
  
	    <legend>Database Information</legend>
	    
	    <TABLE border="1">
	    	<TR>
	    		<TH>Database Name</TH>
	    		<TD><input type="text" name="db_name" value="<?=$config['db_name'];?>"></TD>
	    	</TR>
	    	<TR>
	    		<TH>Database Host</TH>
	    		<TD><input type="text" name="db_host" value="<?=$config['db_host'];?>"></TD>
	    	</TR>
	    	<TR>
	    		<TH>Database User</TH>
	    		<TD><input type="text" name="db_user" value="<?=$config['db_user'];?>"></TD>
	    	</TR>
	    	<TR>
	    		<TH>Database Password</TH>
	    		<TD><input type="password" name="db_password" value="<?=$config['db_password'];?>"></TD>
	    	</TR>
	    
	    </TABLE>
    
    </fieldset>
    
    <DIV class="centered_buttons">	
    	<input type="hidden" name="action" value="db_info" />
   		<input type="submit" name="install_but" value="Next >>" />
    </DIV>
    
</form>
 