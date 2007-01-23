<h1><?=$page_h1;?></h1>

    <fieldset name="auth" class="options">
  	    
		<legend>Site Information</legend>
	    
		<TABLE>
	    	<TR>
	    		<TH scope="row">PHP Version</TH>
	    		<TD><?=$env_status['php_version']['msg'];?></TD>
	    	</TR>
	    		<TH scope="row">Database Connection</TH>
	    		<TD><?=$env_status['db_status']['msg'];?></TD>
	    	<TR>
	    		<TH scope="row">Socket Connections</TH>
	    		<TD><?=$env_status['socket_connection']['msg'];?></TD>
	    	</TR>
	    	<TR>	
	    		<TH scope="row">Log Directory Permissions</TH>
	    		<TD><?=$env_status['log_dir_permissions']['msg'];?></TD>
	    	</TR>
	    </TABLE>
    <BR><BR>
	    <DIV><a href="<?=$_SERVER['PHP_SELF'].'?admin=install.php&action=install_base';?>">Next >> Step 2: Install Database Schema</a></DIV>
    </fieldset>