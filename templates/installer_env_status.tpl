<h1><?=$page_h1;?></h1>

    <fieldset name="auth" class="options">
  	    
		<legend>Site Information</legend>
	    
		<TABLE border="1">
	    	<TR>
	    		<TH scope="row">PHP Version</TH>
	    		<TD></TD>
	    		<TH scope="row">Database Connection</TH>
	    		<TD></TD>
	    		<TH scope="row">Socket Connections</TH>
	    		<TD></TD>
	    		<TH scope="row">Log Directory Permissions</TH>
	    		<TD></TD>
	    	</TR>
	    </TABLE>
    
	    <DIV><a href="<?=$_SERVER['PHP_SELF'].'?action=install_base';?>">Next >> Step 2: Install Database Schema</a></DIV>
    </fieldset>