<h1><?=$page_h1;?></h1>


<DIV class="status_msg">
	<?=$status_msg;?>
</DIV>

<form method="post" action="<?=$_SERVER['PHP_SELF'];?>">
    
    <fieldset>
    	<legend>Packages Available for Installation</legend>
    	<?foreach ($available_packages as $package => $values):?>
    	<input type="radio" name="package" value="<?=$package?>"> <?=$values['package_display_name'];?> - <?=$values['description'];?>
    	<?endforeach;?>
    </fieldset>
    
    <DIV class="centered_buttons">	
    	<input type="hidden" name="action" value="install" />
   		<input type="submit" name="install_but" value="Install Package" />
    </DIV>
    
</form>
 