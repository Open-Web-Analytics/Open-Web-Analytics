<DIV class="panel_headline"><?=$headline;?></DIV>
<div id="panel">
<fieldset>

	<legend>Site Profile</legend>

	<form method="POST">
	
	<table id="panel">
		<?php if ($edit == true):?>
		<TR>
			<TH>Site ID:</TH>
			<TD><?=$site['site_id'];?></TD>
			<input type="hidden" name="<?=$this->getNs();?>site_id" value="<?=$site['site_id'];?>">

		</TR>
		<?php endif;?>
		<TR>
			<TH>Domain:</TH>
			<?php if ($edit == true):?>
			<input type="hidden" name="<?=$this->getNs();?>domain" value="<?=$site['domain'];?>">
			<TD><?=$site['domain'];?></TD>
			<?php else:?>
			<TD>
				
				<select name="<?=$this->getNs();?>protocol">
					<option value="http://">http://</option>
				    <option value="https://">https://</option>
				</select>
  
				<input type="text" name="<?=$this->getNs();?>domain" size="52" maxlength="70" value="<?=$site['domain'];?>"><BR>
				<span class="validation_error"><?=$validation_errors['domain'];?></span>
			</TD>
			<?php endif;?>
		</TR>
		<TR>
			<TH>Site Name:</TH>
			<TD><input type="text" name="<?=$this->getNs();?>name" size="52" maxlength="70" value="<?=$site['name'];?>"></TD>
		</TR>
		<TR>
			<TH>Description:</TH>
			<TD>
				<textarea name="<?=$this->getNs();?>description" cols="52" rows="3"><?=$site['description'];?></textarea>
			</TD>
		</TR>
		<TR>
			<TH></TH>
			<TD>
				<input type="hidden" name="<?=$this->getNs();?>action" value="<?=$action;?>">
				<input type="submit" name="<?=$this->getNs();?>submit_btn" value="Save">
			</TD>
		</TR>
	</table>
		
	</form>
	
</fieldset>
</div>