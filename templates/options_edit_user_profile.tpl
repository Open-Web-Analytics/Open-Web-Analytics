<?=$page_h1;?>

<?=$headline;?>


<fieldset class="options">

<legend>OWA User Accounts</legend>

<TABLE>
	<TR>
		<TH>User</TH>
		<TH>Role</TH>
		<TH>E-mail Address</TH>
		<TH>Options</TH>
	</TR>
	
	<form method="POST" action="<?=$_SERVER['PHP_SELF'];?>">
	<TR>
		<TD><input type="text" size="30" name="real_name" value="<?=$user['real_name']?>"></TD>
		<TD>
		<select>
			<? foreach ($roles as $role => $value):?>
			<option <? if($user['role'] == $role): echo "SELECTED"; endif;?> value="<?=$role;?>"><?=$value['label'];?>
			<? endforeach;?>
		</select>
		
		
		</TD>
		<TD><input type="text" size="30" name="email_address" value="<?=$user['email_address'];?>"></TD>
		<td>Delete user?</td>
		
	</TR>
	
	<TR>
		<TD>
		<input type="hidden" name="admin" value="options.php">
		<input type="hidden" name="action" value="edit_user_profile">
		<input type="hidden" name="user_id" value="<?=$user['user_id'];?>">
		<input type="submit" value="Save" name="save_button"></TD>
	</TR>
	</form>

</TABLE>

</fieldset>
