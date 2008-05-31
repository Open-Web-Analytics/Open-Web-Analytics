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
			<TD><?=$value['user_id'];?></TD>
			<TD><?=$value['real_name'];?></TD>
			<TD><?=$value['role'];?></TD>
			<TD><?=date("F j, Y, g:i a", $value['last_update_date']);?></TD>
			<TD><a href="<?=$this->makeLink(array('view' => 'base.options', 'subview' => 'base.usersEdit', 'user_id' => $value['user_id']));?>">Edit</a> | 
			<? if (count($users) > 1):?>
			<a href="<?=$this->makeLink(array('action' => 'base.usersDelete', 'user_id' => $value['user_id']));?>">Delete</a></TD>
			<?endif;?>
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