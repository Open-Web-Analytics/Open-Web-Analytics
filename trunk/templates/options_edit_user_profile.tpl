
<? if ($error_msg):?>
<DIV class="error"><?=$error_msg;?></DIV>
<?endif;?>

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
		
		<form method="POST" action="<?=$_SERVER['PHP_SELF'];?>">
		<TR>
			<TD><input type="text"<? if ($action == 'edit_user_profile'):?>disabled<? endif;?> size="30" name="user_id" value="<?=$user['user_id']?>"></TD>
			<TD><input type="text" size="30" name="real_name" value="<?=$user['real_name']?>"></TD>
			<TD>
			<select name="role">
				<? foreach ($roles as $role => $value):?>
				<option <? if($user['role'] == $role): echo "SELECTED"; endif;?> value="<?=$role;?>"><?=$value['label'];?>
				<? endforeach;?>
			</select>
			
			
			</TD>
			<TD><input type="text"size="30" name="email_address" value="<?=$user['email_address'];?>"></TD>
			<? if ($action == 'edit_user_profile'): ?>
			<td><a href="<?=$this->make_admin_link('options.php', array('action' => 'delete_user', 'user_id' => $user['user_id']));?>">Delete User</a></td>
			<? else:?>
			<TD></TD>
			<? endif;?>
   
			
		</TR>
		
		<TR>
			<TD>
			<input type="hidden" name="admin" value="options.php">
			<input type="hidden" name="action" value="<?=$action;?>">
			<input type="submit" value="Save" name="save_button"></TD>
		</TR>
		</form>
	
	</TABLE>

</fieldset>
