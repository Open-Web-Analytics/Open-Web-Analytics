<?=$page_h1;?>

<?=$headline;?>

<div class="status"><?=$status;?></div>



<fieldset class="options">

<legend>OWA User Accounts</legend>

<TABLE>
	<TR>
		<TH>User</TH>
		<TH>Role</TH>
		<TH>Last Updated</TH>
		<TH>Options</TH>
	</TR>
	
	<? foreach ($users as $user => $value):?>
	<TR>
		<TD><?=$value['real_name'];?></TD>
		<TD><?=$value['role'];?></TD>
		<TD><?=$value['last_update_date'];?></TD>
		<TD><a href="<?=$this->make_admin_link('options.php', array('owa_page' => 'edit_user_profile', 'user_id' => $value['user_id']));?>">Edit</a></TD>

	</TR>
	<?endforeach;?>

</TABLE>

</fieldset>
