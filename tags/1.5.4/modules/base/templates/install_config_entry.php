<h2>Configuration Settings</h2>
<p>
We could not locate OWA's <code>owa-config.php</code> configuration file. You can use the form below to create the file but this may not work on all hosts. If file generation fails, you can just create it manually by renaming <code>owa-config-dist.php</code> to <code>owa-config.php</code> and filling in your database information and public URL.
</p>
<div id="configSettings">
	<form method="POST">
		
		<h3>Web URL of OWA</h3>
		<p class="form-row">
			<span class="form-label">URL of OWA:</span>
			<span class="form-field">
				<input type="text"size="50" name="<?php echo $this->getNs();?>public_url" value="<?php echo $public_url;?>">
			</span>
			<span class="form-instructions">This is the web URL of OWA's base directory.</span>
		</p>
		
		<h3>Database</h3>
		<p class="form-row">
			<span class="form-label">Database Type:</span>
			<span class="form-field">
				<select name="<?php echo $this->getNs();?>db_type">
					<option value="mysql">Mysql</option>
				</select>
			</span>
			<span class="form-instructions">This is the type of database you are going to use.</span>
		</p>
		
		<p class="form-row">
			<span class="form-label">Database Host:</span>
			<span class="form-field">
				<input type="text"size="30" name="<?php echo $this->getNs();?>db_host" value="<?php echo $config['db_host'];?>">
			</span>
			<span class="form-instructions">This is the host that your database resides on. Localhost is ok.</span>
		</p>
		
		<p class="form-row">
			<span class="form-label">Database Name:</span>
			<span class="form-field">
				<input type="text"size="30" name="<?php echo $this->getNs();?>db_name" value="<?php echo $config['db_name'];?>">
			</span>
			<span class="form-instructions">This is the name of the database to install tables into.</span>
		</p>
		
		<p class="form-row">
			<span class="form-label">Database User:</span>
			<span class="form-field">
				<input type="text"size="30" name="<?php echo $this->getNs();?>db_user" value="<?php echo $config['db_user'];?>">
			</span>
			<span class="form-instructions">This is the user name to connect to the database.</span>
		</p>
		
		<p class="form-row">
			<span class="form-label">Database Password:</span>
			<span class="form-field">
				<input type="password"size="30" name="<?php echo $this->getNs();?>db_password" value="<?php echo $config['db_password'];?>">
			</span>
			<span class="form-instructions">This is the password to connect to the database.</span>
		</p>
		<p>
			<?php echo $this->createNonceFormField('base.installConfig');?>
			<input type="hidden" value="base.installConfig" name="<?php echo $this->getNs();?>action">
			<input class="owa-button"type="submit" value="Continue..." name="<?php echo $this->getNs();?>save_button">
		<p>
		
	</form>
	
</div>