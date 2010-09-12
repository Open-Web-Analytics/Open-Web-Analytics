<DIV class="panel_headline"><?php echo $headline;?></DIV>
<div id="panel">
<fieldset>

	<legend>Site Profile</legend>

	<form method="POST">
	
	<table id="panel">
		<?php if ($edit == true):?>
		<TR>
			<TH>Site ID:</TH>
			<TD><?php echo $site['site_id'];?></TD>
			<input type="hidden" name="<?php echo $this->getNs();?>site_id" value="<?php echo $site['site_id'];?>">

		</TR>
		<?php endif;?>
		<TR>
			<TH>Domain:</TH>
			<?php if ($edit == true):?>
			<input type="hidden" name="<?php echo $this->getNs();?>domain" value="<?php echo $site['domain'];?>">
			<TD><?php echo $site['domain'];?></TD>
			<?php else:?>
			<TD>
				
				<select name="<?php echo $this->getNs();?>protocol">
					<option value="http://">http://</option>
				    <option value="https://">https://</option>
				</select>
  
				<input type="text" name="<?php echo $this->getNs();?>domain" size="52" maxlength="70" value="<?php echo $site['domain'];?>"><BR>
				<span class="validation_error"><?php echo $validation_errors['domain'];?></span>
			</TD>
			<?php endif;?>
		</TR>
		<TR>
			<TH>Site Name:</TH>
			<TD><input type="text" name="<?php echo $this->getNs();?>name" size="52" maxlength="70" value="<?php echo $site['name'];?>"></TD>
		</TR>
		<TR>
			<TH>Description:</TH>
			<TD>
				<textarea name="<?php echo $this->getNs();?>description" cols="52" rows="3"><?php echo $site['description'];?></textarea>
			</TD>
		</TR>
		<TR>
			<TH></TH>
			<TD>
				<?php echo $this->createNonceFormField($action);?>
				<input type="hidden" name="<?php echo $this->getNs();?>action" value="<?php echo $action;?>">
				<input type="submit" name="<?php echo $this->getNs();?>submit_btn" value="Save">
			</TD>
		</TR>
	</table>
		
	</form>
	
</fieldset>
</div>