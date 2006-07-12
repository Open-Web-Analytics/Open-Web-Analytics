

<h1><?=$page_h1;?></h1>

<DIV class="status_msg">
	<?=$status_msg;?>
</DIV>

<form method="post" action="<?=$_SERVER['PHP_SELF'];?>">
    
    <fieldset>
    	<legend>Packages Available for Installation</legend>
    	<TABLE border ="1">
    	<?foreach ($available_packages as $package => $values):?>
    		<TR>
    			<TD><?=$values['package_display_name'];?></TD>
    			<TD><?=$values['description'];?></TD>
    			<TD><a href="<?=$this->make_admin_link('install.php', array('action' => 'install', 'package' => $package));?>">Install</a></TD>
    		</TR>
    	<?endforeach;?>
    	</TABLE>
    
    </fieldset>
    
</form>
 