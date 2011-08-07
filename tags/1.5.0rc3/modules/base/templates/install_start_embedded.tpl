<div id="panel">

	<h2><?php echo $headline;?></h2>
	
	It looks like the Open Web Analytics database still needs to be installed. 
	<BR><BR>
	
	<form method="POST">
	<input type="hidden" name="<?php echo $this->getNs();?>site_id" value="<?php echo $site_id;?>">
	<input type="hidden" name="<?php echo $this->getNs();?>domain" value="<?php echo $domain;?>">
	<input type="hidden" name="<?php echo $this->getNs();?>name" value="<?php echo $name;?>">
	<input type="hidden" name="<?php echo $this->getNs();?>description" value="<?php echo $description;?>">
	<input type="hidden" name="<?php echo $this->getNs();?>do" value="base.installEmbedded">
	
	<input type="hidden" name="<?php echo $this->getNs();?>db_type" value="<?php echo $db_type;?>">
	<input type="hidden" name="<?php echo $this->getNs();?>db_name" value="<?php echo $db_name;?>">
	<input type="hidden" name="<?php echo $this->getNs();?>db_host" value="<?php echo $db_host;?>">
	<input type="hidden" name="<?php echo $this->getNs();?>db_user" value="<?php echo $db_user;?>">
	<input type="hidden" name="<?php echo $this->getNs();?>db_password" value="<?php echo $db_password;?>">
	<input type="hidden" name="<?php echo $this->getNs();?>public_url" value="<?php echo $public_url;?>">
	
	<input type="submit" name="<?php echo $this->getNs();?>submit_btn" value="Install Open Web Analytics">
	
	</form>
	
	
	<BR><BR>
	<P>If at any time you need help, please consult the <a href=<?php echo $this->config['wiki_url'];?>>OWA Wiki</a>.</P>
	
	
</div>
    