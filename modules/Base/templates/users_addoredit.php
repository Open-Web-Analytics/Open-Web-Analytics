<?php /** @var \OWA\Core\ViewScope $view */ ?>
<h1 class="panel_headline"><?php $view->out( $view->headline );?></h1>
<div id="panel">
<fieldset class="options">

    <legend><?php $view->out( \OWA\Core\CoreAPI::t( 'User Profile' ) );?></legend>

    <TABLE class="management owa_formTable">

        <form method="POST">
        <TR>
            <?php // Editing, the user name is text, not a field: nothing to label. ?>
            <TH><?php if ( $view->edit === true ):?><?php $view->out( \OWA\Core\CoreAPI::t( 'User Name' ) );?><?php else:?><label for="owa_userId"><?php $view->out( \OWA\Core\CoreAPI::t( 'User Name' ) );?></label><?php endif;?></TH>
            <TD>
            <?php if ( $view->edit === true ):?>
            <input type="hidden" size="30" name="user_id" value="<?php $view->out( $view->user['user_id'] );?>"><span class="noedit"><?php $view->out( $view->user['user_id'] )?></span>
            <?php else:?>
            <input type="text" size="30" id="owa_userId" name="user_id" value="<?php $view->out( $view->user['user_id'] ?? '' );?>">
            <?php endif;?>
            </TD>
        </TR>

        <?php if ($view->edit === true):?>
        <TR>
            <TH><?php $view->out( \OWA\Core\CoreAPI::t( 'API Key' ) );?></TH>
            <TD><span class="noedit"><?php echo $view->user['api_key'];?></span></TD>
        </TR>
        <?php endif;?>

        <?php if ( ! $view->isAdmin ):?>
        <TR>
            <TH><label for="owa_userRealName"><?php $view->out( \OWA\Core\CoreAPI::t( 'Real Name' ) );?></label></TH>
            <TD><input type="text" size="30" id="owa_userRealName" name="real_name" value="<?php $view->out( $view->getValue( 'real_name', $view->user ) );?>"></TD>
        </TR>
        <TR>
            <TH><label for="owa_userRole"><?php $view->out( \OWA\Core\CoreAPI::t( 'Role' ) );?></label></TH>
            <TD>
            <select id="owa_userRole" name="role">
                <?php foreach ($view->roles as $role):?>
                <option <?php if( isset( $view->user['role'] ) && $view->user['role'] === $role): echo "SELECTED"; endif;?> value="<?php echo $role;?>"><?php echo $role;?></option>
                <?php endforeach;?>
            </select>
            </TD>
        </TR>


        <?php endif;?>
        <TR>
            <TH><label for="owa_userEmail"><?php $view->out( \OWA\Core\CoreAPI::t( 'E-mail Address' ) );?></label></TH>
            <TD><input type="text" size="30" id="owa_userEmail" name="email_address" value="<?php $view->out( $view->user['email_address'] ?? '' );?>"></TD>
        </TR>

        <TR>
            <TD>
                <input type="hidden" name="id" value="<?php $view->out( $view->user['id'] ?? '' );?>">
                <?php echo $view->createNonceFormField($view->action);?>
                <input type="hidden" name="action" value="<?php echo $view->action;?>">
                <input class="owa-button" type="submit" value="<?php $view->out( \OWA\Core\CoreAPI::t( 'Save' ) );?>" name="save_button">
            </TD>
        </TR>
        </form>

    </TABLE>

</fieldset>
<?php if ($view->edit === true):?>
<P>
<fieldset class="options">

    <legend><?php $view->out( \OWA\Core\CoreAPI::t( 'Change Password' ) );?></legend>
    <div style="padding:10px">
    <a href="<?php echo $view->makeLink(array('do' => 'base.passwordResetForm'))?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Change password for this user' ) );?></a>
    </div>
</fieldset>
<P>
<fieldset class="options">

    <legend><?php $view->out( \OWA\Core\CoreAPI::t( 'Application Passwords' ) );?></legend>

    <?php if ( $view->app_passwords ):?>
    <table class="management owa_appPasswords">
        <thead>
            <TR>
                <TH><?php $view->out( \OWA\Core\CoreAPI::t( 'Name' ) );?></TH>
                <TH><?php $view->out( \OWA\Core\CoreAPI::t( 'Access' ) );?></TH>
                <TH><?php $view->out( \OWA\Core\CoreAPI::t( 'Created' ) );?></TH>
                <TH><?php $view->out( \OWA\Core\CoreAPI::t( 'Last used' ) );?></TH>
                <TH><?php $view->out( \OWA\Core\CoreAPI::t( 'From' ) );?></TH>
                <TH></TH>
            </TR>
        </thead>
        <tbody>
            <?php foreach ( $view->app_passwords as $owa_row ):?>
            <TR data-lookup-id="<?php $view->out( $owa_row['lookup_id'] );?>">
                <TD><?php $view->out( $owa_row['name'] );?></TD>
                <TD><?php $view->out( $view->app_password_scopes[ $owa_row['scope'] ] ?? $owa_row['scope'] );?></TD>
                <TD><?php $view->out( date( 'Y-m-d H:i', (int) $owa_row['created_at'] ) );?></TD>
                <TD><?php $view->out( $owa_row['last_used_at'] ? date( 'Y-m-d H:i', (int) $owa_row['last_used_at'] ) : \OWA\Core\CoreAPI::t( 'Never' ) );?></TD>
                <TD><?php $view->out( (string) $owa_row['last_used_ip'] );?></TD>
                <TD>
                    <form method="post" style="margin:0" action="<?php echo $view->makeLink( array( 'do' => 'base.userAppPasswordRevoke' ) );?>">
                        <?php echo $view->createNonceFormField( 'base.userAppPasswordRevoke' );?>
                        <input type="hidden" name="user_id" value="<?php $view->out( $view->user['user_id'] );?>">
                        <input type="hidden" name="lookup_id" value="<?php $view->out( $owa_row['lookup_id'] );?>">
                        <button type="submit" class="owa-button owa-button-quiet owa_appPasswordRevoke"
                                data-owa-confirm
                                data-owa-confirm-title="<?php $view->out( \OWA\Core\CoreAPI::t( 'Revoke this application password?' ) );?>"
                                data-owa-confirm-body="<?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Anything using “%1$s” stops working immediately. Only %2$s can make a new one.' ), $owa_row['name'], $view->user['user_id'] ) );?>"
                                data-owa-confirm-proceed="<?php $view->out( \OWA\Core\CoreAPI::t( 'Revoke' ) );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Revoke' ) );?></button>
                    </form>
                </TD>
            </TR>
            <?php endforeach;?>
        </tbody>
    </table>
    <?php else:?>
    <div style="padding:10px" class="owa_appPasswordsNone"><?php $view->out( \OWA\Core\CoreAPI::t( 'This user has no application passwords.' ) );?></div>
    <?php endif;?>
</fieldset>
<?php endif;?>
</div>