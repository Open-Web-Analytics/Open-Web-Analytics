

<h1><?=$page_h1;?></h1>

<DIV class="status_msg">
	<?=$status_msg;?>
</DIV>

<div id="login_box">
	<form method="POST" action="<?=$_SERVER['PHP_SELF'];?>">
    
    <fieldset>
    	<legend>New Password</legend>
    	<TABLE>
		
	
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
			<INPUT type="submit" size="30" name="submit_btn" value="Save Your New Password"></TD>
		</TR>
    	
    	</TABLE>
    
    </fieldset>
    
	</form>
 </div>