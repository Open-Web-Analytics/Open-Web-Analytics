<div class="panel_headline"><?php echo $headline;?></div>

<div class="subview_content">
	
	<h1>Installation Complete.   (wasn't that easy?)</h1>
	
	<h2>Before you can start using OWA you must...</h2>
	
	<h3>1. Set Your Admin User Password</h3>
	
	You must set the admin password of your account. 
	
	<table>
		<TR>
			<TH>User Name:</TH>
			<TD><?php echo $u;?></TD>
		</TR>
		<TR>
			<TH>Password:</TH>
			<TD><a href="<?php echo $this->makeAbsoluteLink(array('do' => 'base.usersPasswordEntry', 'k' => $key));?>"> Click here to set password</a></TD>
		</TR>
	</table>
	
	<h3>2. Place Javascript Tracking Tags or Use the PHP API</h3>
	
	<?php include('invocation.tpl');?>

</div>