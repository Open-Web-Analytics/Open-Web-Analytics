<div class="panel_headline"><?=$headline;?></div>
<div id="panel">
<fieldset class="options">

	<legend>User Profile</legend>
	
	<TABLE class="form">
	
		<form method="POST">
		<TR>
			<TH>User Name</TH>
			<TD><input type="text"<? if ($action == 'base.usersEdit'):?>disabled<? endif;?> size="30" name="<?=$this->getNs();?>user_id" value="<?=$user['user_id']?>"></TD>
		</TR>
		<TR>
			<TH>Real Name</TH>
			<TD><input type="text" size="30" name="<?=$this->getNs();?>real_name" value="<?=$user['real_name']?>"></TD>
		</TR>
		<TR>	
			<TH>Role</TH>
			<TD>
			<select name="<?=$this->getNs();?>role">
				<? foreach ($roles as $role => $value):?>
				<option <? if($user['role'] == $role): echo "SELECTED"; endif;?> value="<?=$role;?>"><?=$value['label'];?>
				<? endforeach;?>
			</select>
			
			
			</TD>
		</TR>
		<TR>
			<TH>E-mail Address</TH>
			<TD><input type="text"size="30" name="<?=$this->getNs();?>email_address" value="<?=$user['email_address'];?>"></TD>
		</TR>
		<TR>
			<? if ($action == 'base.usersEdit'): ?>
			<TH>Options</TH>
			<td><a href="<?=$this->makeLink(array('action' => 'base.usersDelete', 'user_id' => $user['user_id']));?>">Delete User</a></td>
		
			<? else:?>
			<TD></TD>
			<? endif;?>
			
		</TR>
		
		<TR>
			<TD>
				<input type="hidden" name="<?=$this->getNs();?>action" value="<?=$action;?>">
				<input type="submit" value="Save" name="<?=$this->getNs();?>save_button">
			</TD>
		</TR>
		</form>
	
	</TABLE>

</fieldset>
</div>