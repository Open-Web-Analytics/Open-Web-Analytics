<div class="panel_headline"><?=$headline;?></div>
<div id="panel">
<fieldset>

	<legend>Users <span class="legend_link">(<a href="<?=$this->makeLink(array('view' => 'base.options', 'subview' => 'base.usersAdd'));?>">Add New User</a>)</span></legend>
	
	<TABLE class="data">
	
	<? if($users):?>
	
		<TR>
			<TH>User Id</TH>
			<TH>Real Name</TH>
			<TH>Role</TH>
			<TH>Last Updated</TH>
			<TH>Options</TH>
		</TR>
		
	
		
		<? foreach ($users as $user => $value):?>
		<TR>
			<TD><?=$value['user_user_id'];?></TD>
			<TD><?=$value['user_real_name'];?></TD>
			<TD><?=$value['user_role'];?></TD>
			<TD><?=date("F j, Y, g:i a", $value['user_last_update_date']);?></TD>
			<TD><a href="<?=$this->makeLink(array('view' => 'base.options', 'subview' => 'base.usersEdit', 'user_id' => $value['user_user_id']));?>">Edit</a> | <a href="<?=$this->makeLink(array('action' => 'base.usersDelete', 'user_id' => $value['user_user_id']));?>">Delete</a></TD>
	
		</TR>
		<?endforeach;?>
		
	<? else:?>
		
		<TR>
			<TD>There are no User Accounts.</TD>
		</TR>
	
	<? endif;?>
		
	</TABLE>

</fieldset>
</div>