<fieldset class="options">

	<legend><?=$headline;?></legend>
	
	<TABLE>
		<TR>
			<TH>User Name</TH>
			<TH>Real Name</TH>
			<TH>Role</TH>
			<TH>E-mail Address</TH>
			<TH>Options</TH>
		</TR>
		
		<form method="POST">
		<TR>
			<TD><input type="text"<? if ($action == 'base.usersEdit'):?>disabled<? endif;?> size="30" name="<?=$this->getNs();?>user_id" value="<?=$user['user_id']?>"></TD>
			<TD><input type="text" size="30" name="<?=$this->getNs();?>real_name" value="<?=$user['real_name']?>"></TD>
			<TD>
			<select name="<?=$this->getNs();?>role">
				<? foreach ($roles as $role => $value):?>
				<option <? if($user['role'] == $role): echo "SELECTED"; endif;?> value="<?=$role;?>"><?=$value['label'];?>
				<? endforeach;?>
			</select>
			
			
			</TD>
			<TD><input type="text"size="30" name="<?=$this->getNs();?>email_address" value="<?=$user['email_address'];?>"></TD>
			<? if ($action == 'base.usersEdit'): ?>
			<td><a href="<?=$this->makeLink(array('action' => 'base.userDelete', 'user_id' => $user['user_id']));?>">Delete User</a></td>
			<? else:?>
			<TD></TD>
			<? endif;?>
   
			
		</TR>
		
		<TR>
			<TD>
			<input type="hidden" name="<?=$this->getNs();?>action" value="<?=$action;?>">
			<input type="submit" value="Save" name="<?=$this->getNs();?>save_button"></TD>
		</TR>
		</form>
	
	</TABLE>

</fieldset>
