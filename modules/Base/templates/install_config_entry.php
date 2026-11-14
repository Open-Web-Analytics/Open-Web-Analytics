<?php /** @var \OWA\Core\ViewScope $view */ ?>
<h2><?php $view->out( \OWA\Core\CoreAPI::t( 'Configuration Settings' ) ); ?></h2>
<p class="owa_publicIntro">
<?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'We could not find OWA\'s %1$s. The form below creates it. If your server does not let PHP write the file, copy %2$s to %1$s yourself and fill in the same details.' ), '<code>owa-config.php</code>', '<code>owa-config-dist.php</code>' ); ?>
</p>
<div id="configSettings">
    <form method="POST">

        <h3><?php $view->out( \OWA\Core\CoreAPI::t( 'Public URL' ) ); ?></h3>
        <div class="owa_installField">
            <label><?php $view->out( \OWA\Core\CoreAPI::t( 'Public URL' ) ); ?></label>
            <span class="owa_installInput">
                <input type="text"size="50" name="public_url" value="<?php echo $view->public_url;?>">
            </span>
            <span class="owa_installHint"><?php $view->out( \OWA\Core\CoreAPI::t( 'This is the web URL of OWA\'s base directory.' ) ); ?></span>
        </div>

        <h3><?php $view->out( \OWA\Core\CoreAPI::t( 'Database' ) ); ?></h3>
        <div class="owa_installField">
            <label><?php $view->out( \OWA\Core\CoreAPI::t( 'Database Type' ) ); ?></label>
            <span class="owa_installInput">
                <select name="db_type">
	                <?php foreach ( $view->owaTemplate()->config['db_supported_types'] as $db_type => $db_label ): ?>
                    <option value="<?php $view->out( $db_type );?>"><?php $view->out( $db_label );?></option>
                    <?php endforeach;?>
                </select>
            </span>
            <span class="owa_installHint"><?php $view->out( \OWA\Core\CoreAPI::t( 'OWA tells MySQL from MariaDB itself, once it connects.' ) ); ?></span>
        </div>

        <div class="owa_installField">
            <label><?php $view->out( \OWA\Core\CoreAPI::t( 'Database Host' ) ); ?></label>
            <span class="owa_installInput">
                <input type="text"size="30" name="db_host" value="<?php echo $view->config['db_host'] ?? '';?>">
            </span>
            <span class="owa_installHint"><?php $view->out( \OWA\Core\CoreAPI::t( 'This is the host that your database resides on. Localhost is ok.' ) ); ?></span>
        </div>

        <div class="owa_installField">
            <label><?php $view->out( \OWA\Core\CoreAPI::t( 'Database Port' ) ); ?></label>
            <span class="owa_installInput">
                <input type="text"size="30" name="db_port" value="<?php echo (!empty($view->config['db_port']) ? $view->config['db_port'] : 3306);?>">
            </span>
            <span class="owa_installHint"><?php $view->out( \OWA\Core\CoreAPI::t( '(optional) The port of your database. Will default to port 3306 if you leave this empty.' ) ); ?></span>
        </div>

        <div class="owa_installField">
            <label><?php $view->out( \OWA\Core\CoreAPI::t( 'Database' ) ); ?></label>
            <span class="owa_installInput">
                <label>
                    <input type="radio" name="db_mode" value="existing"<?php if ( $view->config['db_mode'] === 'existing' ): ?> checked<?php endif; ?>>
                    <?php $view->out( \OWA\Core\CoreAPI::t( 'Use an existing database for this install' ) ); ?>
                </label><br>
                <input type="text" size="30" name="db_name" value="<?php $view->out( (string) ( $view->config['db_name'] ?? '' ) ); ?>" placeholder="<?php $view->out( \OWA\Core\CoreAPI::t( 'its name' ) ); ?>"><br>
                <label>
                    <input type="radio" name="db_mode" value="create"<?php if ( $view->config['db_mode'] === 'create' ): ?> checked<?php endif; ?>>
                    <?php $view->out( \OWA\Core\CoreAPI::t( 'Let OWA create a new database for this install (CREATE permissions required)' ) ); ?>
                </label><br>
                <code><?php $view->out( (string) $view->config['db_create_name'] ); ?></code>
                <input type="hidden" name="db_create_name" value="<?php $view->out( (string) $view->config['db_create_name'] ); ?>">
            </span>
            <span class="owa_installHint"><?php $view->out( \OWA\Core\CoreAPI::t( 'A database OWA creates is named for this install\'s Organization, so it can become one Organization\'s database when OWA hosts several.' ) ); ?></span>
        </div>

        <div class="owa_installField">
            <label><?php $view->out( \OWA\Core\CoreAPI::t( 'Database User' ) ); ?></label>
            <span class="owa_installInput">
                <input type="text"size="30" name="db_user" value="<?php echo $view->config['db_user'] ?? '';?>">
            </span>
            <span class="owa_installHint"><?php $view->out( \OWA\Core\CoreAPI::t( 'This is the user name to connect to the database.' ) ); ?></span>
        </div>

        <div class="owa_installField">
            <label><?php $view->out( \OWA\Core\CoreAPI::t( 'Database Password' ) ); ?></label>
            <span class="owa_installInput">
                <input type="password"size="30" name="db_password" value="<?php echo $view->config['db_password'] ?? '';?>">
            </span>
            <span class="owa_installHint"><?php $view->out( \OWA\Core\CoreAPI::t( 'This is the password to connect to the database.' ) ); ?></span>
        </div>
        <div class="owa_installActions">
            <?php echo $view->createNonceFormField('base.installConfig');?>
            <input type="hidden" value="base.installConfig" name="action">
            <input class="owa-button owa_publicSubmit" type="submit" value="<?php $view->out( \OWA\Core\CoreAPI::t( 'Continue' ) ); ?>"
                   name="save_button">
        </div>

    </form>

</div>