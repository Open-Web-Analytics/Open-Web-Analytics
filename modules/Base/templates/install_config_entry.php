<?php /** @var \OWA\Core\ViewScope $view */ ?>
<h2>Configuration Settings</h2>
<p class="owa_publicIntro">
We could not find OWA's <code>owa-config.php</code>. The form below creates it. If your
server does not let PHP write the file, copy <code>owa-config-dist.php</code> to
<code>owa-config.php</code> yourself and fill in the same details.
</p>
<div id="configSettings">
    <form method="POST">

        <h3>Public URL</h3>
        <div class="owa_installField">
            <label>Public URL</label>
            <span class="owa_installInput">
                <input type="text"size="50" name="<?php echo $view->getNs();?>public_url" value="<?php echo $view->public_url;?>">
            </span>
            <span class="owa_installHint">This is the web URL of OWA's base directory.</span>
        </div>

        <h3>Database</h3>
        <div class="owa_installField">
            <label>Database Type</label>
            <span class="owa_installInput">
                <select name="<?php echo $view->getNs();?>db_type">
	                <?php foreach ( $this->config['db_supported_types'] as $db_type => $db_label ): ?>
                    <option value="<?php $view->out( $db_type );?>"><?php $view->out( $db_label );?></option>
                    <?php endforeach;?>
                </select>
            </span>
            <span class="owa_installHint">This is the type of database you are going to use.</span>
        </div>

        <div class="owa_installField">
            <label>Database Host</label>
            <span class="owa_installInput">
                <input type="text"size="30" name="<?php echo $view->getNs();?>db_host" value="<?php echo $view->config['db_host'] ?? '';?>">
            </span>
            <span class="owa_installHint">This is the host that your database resides on. Localhost is ok.</span>
        </div>

        <div class="owa_installField">
            <label>Database Port</label>
            <span class="owa_installInput">
                <input type="text"size="30" name="<?php echo $view->getNs();?>db_port" value="<?php echo (!empty($view->config['db_port']) ? $view->config['db_port'] : 3306);?>">
            </span>
            <span class="owa_installHint">(optional) The port of your database. Will default to port 3306 if you leave this empty.</span>
        </div>

        <div class="owa_installField">
            <label>Database Name</label>
            <span class="owa_installInput">
                <input type="text"size="30" name="<?php echo $view->getNs();?>db_name" value="<?php echo $view->config['db_name'] ?? '';?>">
            </span>
            <span class="owa_installHint">This is the name of the database to install tables into.</span>
        </div>

        <div class="owa_installField">
            <label>Database User</label>
            <span class="owa_installInput">
                <input type="text"size="30" name="<?php echo $view->getNs();?>db_user" value="<?php echo $view->config['db_user'] ?? '';?>">
            </span>
            <span class="owa_installHint">This is the user name to connect to the database.</span>
        </div>

        <div class="owa_installField">
            <label>Database Password</label>
            <span class="owa_installInput">
                <input type="password"size="30" name="<?php echo $view->getNs();?>db_password" value="<?php echo $view->config['db_password'] ?? '';?>">
            </span>
            <span class="owa_installHint">This is the password to connect to the database.</span>
        </div>
        <div class="owa_installActions">
            <?php echo $view->createNonceFormField('base.installConfig');?>
            <input type="hidden" value="base.installConfig" name="<?php echo $view->getNs();?>action">
            <input class="owa-button owa_publicSubmit" type="submit" value="Continue"
                   name="<?php echo $view->getNs();?>save_button">
        </div>

    </form>

</div>