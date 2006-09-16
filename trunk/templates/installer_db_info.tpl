<h1><?=$page_h1;?></h1>

<form method="get" action="<?=$_SERVER['PHP_SELF'];?>">

    <fieldset name="auth" class="options">
  
	 <!--   <legend>Database Information</legend>
	    
	    <TABLE border="1">
	    	<TR>
	    		<TH>Database Name</TH>
	    		<TD><input type="text" name="db_name" value="<?=//$config['db_name'];?>"></TD>
	    	</TR>
	    	<TR>
	    		<TH>Database Host</TH>
	    		<TD><input type="text" name="db_host" value="<?=//$config['db_host'];?>"></TD>
	    	</TR>
	    	<TR>
	    		<TH>Database User</TH>
	    		<TD><input type="text" name="db_user" value="<?=//$config['db_user'];?>"></TD>
	    	</TR>
	    	<TR>
	    		<TH>Database Password</TH>
	    		<TD><input type="password" name="db_password" value="<?=//$config['db_password'];?>"></TD>
	    	</TR>
	    
	    </TABLE>  -->
	    
	 <legend>Site Information</legend>
	      <TABLE border="1">
	    	<TR>
	    		<TH>Site Name</TH>
	    		<TD><input type="text" name="name" value=""></TD>
	    	</TR>
	    	<TR>
	    		<TH>Site Description</TH>
	    		<TD><input type="text" name="description" value=""></TD>
	    	</TR>
	    
	    </TABLE>
    
    </fieldset>
    
    <DIV class="centered_buttons">	
    	<input type="hidden" name="action" value="save_site_info" />
   		<input type="submit" name="install_but" value="Next >>" />
    </DIV>
    
</form>
 