<div class="subview_content">
	
	<h2>Whoops. It looks like OWA is already installed!</h2>
	
	<p>To re-install OWA, drop all owa_ tables run the installer again.</p>
	<BR>
	<p>		
		<a href="<?php echo $this->makeLink(array("action" => "base.loginForm"), false, owa_coreAPI::getSetting('base','public_url'));?>">
			<span class="owa-button">Login</span>
		</a>	
	</p>	
	

</div>