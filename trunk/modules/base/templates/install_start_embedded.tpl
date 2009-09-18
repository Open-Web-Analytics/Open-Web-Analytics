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
	<input type="submit" name="<?php echo $this->getNs();?>submit_btn" value="Install Open Web Analytics">
	
	</form>
	
	
	<BR><BR>
	<P>If at any time you need help, please consult the <a href=<?php echo $this->config['wiki_url'];?>>OWA Wiki</a>.</P>
	
	
</div>
    