

<h1><?=$page_h1;?></h1>

<? if ($error_msg):?>
<DIV class="error">
	<?=$error_msg;?>
</DIV>
<?endif;?>

<DIV id="login_box">
<form method="POST" action="<?=$_SERVER['PHP_SELF'];?>">
    
    <fieldset>
    	<legend>Login</legend>
    	<TABLE>
		<TR>
			<TH scope="row">User Name:</TH>
			<TD><INPUT type="text" size="30" name="user_id"></TD>
		</TR>
	
		<TR>
			<TH scope="row">Password:</TH>
			<TD><INPUT type="password" size="20" name="password"></TD>
		</TR>
		
		<TR>
			<TH scope="row"></TH>
			<TD>
			<input type="hidden" size="70" name="go" value="<?=$go?>">
			<input name="action" value="auth" type="hidden">
			<INPUT type="submit" name="submit_btn" value="Login"></TD>
		</TR>
		
		<TR>
			<TD></TD>
			<TD>
				<BR><span class="info_text"><a href="<?=$_SERVER['PHP_SELF'].'?page=request_new_password';?>">Forgot your password?</a></span>
			</TD>
		</TR>
    	
    	</TABLE>
    
    </fieldset>
    
</form>
 
</DIV>