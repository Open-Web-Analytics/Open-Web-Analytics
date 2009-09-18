<div class="panel_headline"><?php echo $headline;?></div>

<fieldset>
	
	<TABLE id="panel">
	
		<form method="POST">
		<TR valign="top">
			<TH>User Name:</TH>
			<TD>Admin</TD>
		</TR>
		<TR valign="top">
			<TH>Real Name:</TH>
			<TD><input type="text" size="30" name="<?php echo $this->getNs();?>real_name" value="<?php echo $user['real_name']?>"></TD>
		</TR>
		<TR>	
			
		</TR>
		<TR valign="top">
			<TH>E-mail Address:</TH>
			<TD><input type="text"size="30" name="<?php echo $this->getNs();?>email_address" value="<?php echo $user['email_address'];?>"></TD>
		</TR>		
		<TR>
			<TD>
				<input type="hidden" name="<?php echo $this->getNs();?>action" value="base.installAdminUser">
				<input type="submit" value="Save" name="<?php echo $this->getNs();?>save_button">
			</TD>
		</TR>
		</form>
	
	</TABLE>

</fieldset>
