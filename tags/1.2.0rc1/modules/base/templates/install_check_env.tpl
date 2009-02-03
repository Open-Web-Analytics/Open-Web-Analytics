<div class="panel_headline"><?=$headline;?></div>
<BR>
    <fieldset name="" class="">
  	    
		<legend>Environment Checks</legend>
	    
		<TABLE>
	    	<TR>
	    		<TH scope="row">PHP Version</TH>
	    		<TD class="<? if ($errors['php_version']):?>red<?elseif ($warnings['php_version']):?>yellow<?else:?>green<?endif;?>">
	    		<?=$env['php_version'];?></TD>
	    		<TD><?=$errors['php_version'];?></TD>
	    	</TR>
	    		<TH scope="row">Database Connection</TH>
	    		<TD class="<?php if ($errors['db_status']):?>red
							<?php elseif ($warnings['db_status']):?>yellow
							<?php else:?>green<?php endif;?>">
	    			<?=$env['db_status'];?>
	    		</TD>
	    		
	    		<TD><?=$errors['db_status'];?></TD>
	    	<TR>
	    		<TH scope="row">Socket Connections</TH>
	    		<TD class="<?php if ($errors['socket_connection']):?>red
							<?php elseif ($warnings['socket_connection']):?>yellow
							<?php else:?>green<?php endif;?>">
	    			<?=$env['socket_connection'];?>
	    		</TD>
	    		<TD><?=$errors['socket_connection'];?></TD>
	    	</TR>
	    	<TR>	
	    		<TH scope="row">File System Permissions</TH>
	    		<TD class="<?php if ($errors['log_dir_permission']):?>red
							<?php elseif ($warnings['log_dir_permission']):?>yellow
							<?php else:?>green<?php endif;?>">
	    			<?=$env['log_dir_permissions'];?></TD>
	    		<TD><?=$errors['log_dir_permissions'];?></TD>
	    	</TR>
	    </TABLE>
    </fieldset>
	 	<BR><BR>
    
    <?php if ($errors['count'] == 0):?>
    	<DIV class="owa_wizardNextText">	
			<a href="<?=$this->makeLink(array('action' => 'base.installBase'));?>">Continue >></a>
		</DIV>
	<?php else:?>
	
	<span class="error">Please resolve these environment issues and then try this installation again.</span>
		
	<?php endif;?>
    
	