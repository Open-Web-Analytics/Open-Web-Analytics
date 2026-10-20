<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline"><?php $view->out( $view->headline );?></div>
<div id="panel">
<fieldset class="options">

    <legend>User Profile</legend>

    <TABLE class="management owa_formTable">

        <form method="POST">
        <TR>
            <TH>User Name</TH>
            <TD>
            <?php if ( $view->edit === true ):?>
            <input type="hidden" size="30" name="<?php echo $view->getNs();?>user_id" value="<?php $view->out( $view->user['user_id'] );?>"><span class="noedit"><?php $view->out( $view->user['user_id'] )?></span>
            <?php else:?>
            <input type="text" size="30" name="<?php echo $view->getNs();?>user_id" value="<?php $view->out( $view->user['user_id'] ?? '' );?>">
            <?php endif;?>
            </TD>
        </TR>

        <?php if ($view->edit === true):?>
        <TR>
            <TH>API Key</TH>
            <TD><span class="noedit"><?php echo $view->user['api_key'];?></span></TD>
        </TR>
        <?php endif;?>

        <?php if ( ! $view->isAdmin ):?>
        <TR>
            <TH>Real Name</TH>
            <TD><input type="text" size="30" name="<?php echo $view->getNs();?>real_name" value="<?php $view->out( $view->getValue( 'real_name', $view->user ) );?>"></TD>
        </TR>
        <TR>
            <TH>Role</TH>
            <TD>
            <select name="<?php echo $view->getNs();?>role">
                <?php foreach ($view->roles as $role):?>
                <option <?php if( isset( $view->user['role'] ) && $view->user['role'] === $role): echo "SELECTED"; endif;?> value="<?php echo $role;?>"><?php echo $role;?></option>
                <?php endforeach;?>
            </select>
            </TD>
        </TR>


        <?php endif;?>
        <TR>
            <TH>E-mail Address</TH>
            <TD><input type="text"size="30" name="<?php echo $view->getNs();?>email_address" value="<?php $view->out( $view->user['email_address'] ?? '' );?>"></TD>
        </TR>

        <TR>
            <TD>
                <input type="hidden" name="<?php echo $view->getNs();?>id" value="<?php $view->out( $view->user['id'] ?? '' );?>">
                <?php echo $view->createNonceFormField($view->action);?>
                <input type="hidden" name="<?php echo $view->getNs();?>action" value="<?php echo $view->action;?>">
                <input class="owa-button" type="submit" value="Save" name="<?php echo $view->getNs();?>save_button">
            </TD>
        </TR>
        </form>

    </TABLE>

</fieldset>
<?php if ($view->edit === true):?>
<P>
<fieldset class="options">

    <legend>Change Password</legend>
    <div style="padding:10px">
    <a href="<?php echo $view->makeLink(array('do' => 'base.passwordResetForm'))?>">Change password for this user</a>
    </div>
</fieldset>
<P>
<fieldset class="options">

    <legend>Application Passwords</legend>

    <?php if ( $view->app_passwords ):?>
    <table class="management owa_appPasswords">
        <thead>
            <TR>
                <TH>Name</TH>
                <TH>Access</TH>
                <TH>Created</TH>
                <TH>Last used</TH>
                <TH>From</TH>
                <TH></TH>
            </TR>
        </thead>
        <tbody>
            <?php foreach ( $view->app_passwords as $owa_row ):?>
            <TR data-lookup-id="<?php $view->out( $owa_row['lookup_id'] );?>">
                <TD><?php $view->out( $owa_row['name'] );?></TD>
                <TD><?php $view->out( $view->app_password_scopes[ $owa_row['scope'] ] ?? $owa_row['scope'] );?></TD>
                <TD><?php $view->out( date( 'Y-m-d H:i', (int) $owa_row['created_at'] ) );?></TD>
                <TD><?php $view->out( $owa_row['last_used_at'] ? date( 'Y-m-d H:i', (int) $owa_row['last_used_at'] ) : 'Never' );?></TD>
                <TD><?php $view->out( (string) $owa_row['last_used_ip'] );?></TD>
                <TD>
                    <form method="post" style="margin:0" action="<?php echo $view->makeLink( array( 'do' => 'base.userAppPasswordRevoke' ) );?>">
                        <?php echo $view->createNonceFormField( 'base.userAppPasswordRevoke' );?>
                        <input type="hidden" name="user_id" value="<?php $view->out( $view->user['user_id'] );?>">
                        <input type="hidden" name="lookup_id" value="<?php $view->out( $owa_row['lookup_id'] );?>">
                        <button type="submit" class="owa-button owa-button-quiet owa_appPasswordRevoke"
                                data-owa-confirm
                                data-owa-confirm-title="Revoke this application password?"
                                data-owa-confirm-body="Anything using &ldquo;<?php $view->out( $owa_row['name'] );?>&rdquo; stops working immediately. Only <?php $view->out( $view->user['user_id'] );?> can make a new one."
                                data-owa-confirm-proceed="Revoke">Revoke</button>
                    </form>
                </TD>
            </TR>
            <?php endforeach;?>
        </tbody>
    </table>
    <?php else:?>
    <div style="padding:10px" class="owa_appPasswordsNone">This user has no application passwords.</div>
    <?php endif;?>
</fieldset>
<?php endif;?>
</div>