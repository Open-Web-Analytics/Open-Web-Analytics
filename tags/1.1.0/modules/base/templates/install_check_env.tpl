<div class="panel_headline"><?=$headline;?></div>

    <fieldset name="" class="">
  	    
		<legend>Server Environment</legend>
	    
		<TABLE>
	    	<TR>
	    		<TH scope="row">PHP Version</TH>
	    		<TD class="<? if ($errors['php_version']):?>red<?elseif ($warnings['php_version']):?>yellow<?else:?>green<?endif;?>">
	    		<?=$env['php_version'];?></TD>
	    		<TD><?=$errors['php_version'];?></TD>
	    	</TR>
	    		<TH scope="row">Database Connection</TH>
	    		<TD class="<? if ($errors['db_status']):?>red
							<?elseif ($warnings['db_status']):?>yellow
							<?else:?>green<?endif;?>">
	    			<?=$env['db_status'];?>
	    		</TD>
	    		
	    		<TD><?=$errors['db_status'];?></TD>
	    	<TR>
	    		<TH scope="row">Socket Connections</TH>
	    		<TD class="<? if ($errors['socket_connection']):?>red
							<?elseif ($warnings['socket_connection']):?>yellow
							<?else:?>green<?endif;?>">
	    			<?=$env['socket_connection'];?>
	    		</TD>
	    		<TD><?=$errors['socket_connection'];?></TD>
	    	</TR>
	    	<TR>	
	    		<TH scope="row">File System Permissions</TH>
	    		<TD class="<? if ($errors['log_dir_permission']):?>red
							<?elseif ($warnings['log_dir_permission']):?>yellow
							<?else:?>green<?endif;?>">
	    			<?=$env['log_dir_permissions'];?></TD>
	    		<TD><?=$errors['log_dir_permissions'];?></TD>
	    	</TR>
	    </TABLE>
    
	 	<BR><BR>
    
    <? if ($errors['count'] == 0):?>
    	<DIV class="centered_buttons">	
			<a href="<?=$this->makeLink(array('action' => 'base.installBase'));?>">Next >> Next Step: Default Site Setup</a>
		</DIV>
	<? else:?>
	
	<span class="error">Please resolve these environment issues and then try this installation again.</span>
		
	<?endif;?>
    
	</fieldset>