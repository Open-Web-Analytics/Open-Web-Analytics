<div class="panel_headline"><?=$headline;?></div>
<div id="panel">
<fieldset class="options">

	<legend>User Profile</legend>
	
	<TABLE class="form">
	
		<form method="POST">
		<TR>
			<TH>User Name</TH>
			<TD>
			<?php if ($edit === true):?>
			<input type="hidden" size="30" name="<?=$this->getNs();?>user_id" value="<?=$user['user_id']?>"><?=$user['user_id']?>
			<? else:?>
			<input type="text" size="30" name="<?=$this->getNs();?>user_id" value="<?=$user['user_id']?>">
			<?php endif;?>
			</TD>
		</TR>
		<TR>
			<TH>Real Name</TH>
			<TD><input type="text" size="30" name="<?=$this->getNs();?>real_name" value="<?=$user['real_name']?>"></TD>
		</TR>
		<?php if ($user['id'] != 1):?>
		<TR>	
			<TH>Role</TH>
			<TD>
			<select name="<?=$this->getNs();?>role">
				<?php foreach ($roles as $role):?>
				<option <? if($user['role'] === $role): echo "SELECTED"; endif;?> value="<?=$role;?>"><?=$role;?>
				<?php endforeach;?>
			</select>
			</TD>
		</TR>
		<?php endif;?>
		<TR>
			<TH>E-mail Address</TH>
			<TD><input type="text"size="30" name="<?=$this->getNs();?>email_address" value="<?=$user['email_address'];?>"></TD>
		</TR>
		
		<TR>
			<TD>
				<input type="hidden" name="<?=$this->getNs();?>id" value="<?=$user['id'];?>">
				<input type="hidden" name="<?=$this->getNs();?>action" value="<?=$action;?>">
				<input type="submit" value="Save" name="<?=$this->getNs();?>save_button">
			</TD>
		</TR>
		</form>
	
	</TABLE>

</fieldset>
</div>