

<h1><?=$page_h1;?></h1>

<div id="login_box">
<form method="POST">
    
    <fieldset>
    	<legend>Password Request Form</legend>
    	<TABLE>
		<TR>
			<TH scope="row">Your E-mail Address:</TH>
			<TD><INPUT type="text" size="30" name="<?=$this->getNs();?>email_address" value=""></TD>
		</TR>
		
		<TR>
			<TH scope="row"></TH>
			<TD>
				<input name="<?=$this->getNs();?>action" value="base.passwordResetRequest" type="hidden">
				<INPUT type="submit" size="30" name="<?=$this->getNs();?>submit" value="Request New Password">
			</TD>
		</TR>
    	
    	</TABLE>
    
    </fieldset>
    
</form>
 </div>