<div id="panel">

	<h2><?=$headline;?></h2>
	
	It looks like the Open Web Analytics database still needs to be installed. 
	<BR><BR>
	
	<form method="POST">
	<input type="hidden" name="<?=$this->getNs();?>site_id" value="<?=$site_id;?>">
	<input type="hidden" name="<?=$this->getNs();?>domain" value="<?=$domain;?>">
	<input type="hidden" name="<?=$this->getNs();?>name" value="<?=$name;?>">
	<input type="hidden" name="<?=$this->getNs();?>description" value="<?=$description;?>">
	<input type="hidden" name="<?=$this->getNs();?>do" value="base.installEmbedded">
	<input type="submit" name="<?=$this->getNs();?>submit_btn" value="Install Open Web Analytics">
	
	</form>
	
	
	<BR><BR>
	<P>If at any time you need help, please consult the <a href=<?=$this->config['wiki_url'];?>>OWA Wiki</a>.</P>
	
	
</div>
    