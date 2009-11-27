<div class="subview_content">
	
	<h1>That's It. Installation is Complete.</h1>
	<p>Open Web Analytics has been successfully installed. Login using the user name and password below.</p>
	<p class="form-row">
		<span class="form-label">User Name:</span>
		<span class="form-value"><?php echo $u;?></span>
	</p>
	<p class="form-row">
		<span class="form-label">Password:</span>
		<span class="form-value"><?php echo $p;?></span>
		<span class="form-instructions">Be sure to change this password.</span>
	</p>
	<BR>
	<p>		
		<a href="<?php echo $this->makeLink(array("action" => "base.loginForm"), false, owa_coreAPI::getSetting('base','public_url'));?>">
			<span class="owa-button">Login</span>
		</a>	
	</p>	
</div>