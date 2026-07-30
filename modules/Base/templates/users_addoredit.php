<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="panel_headline"><?php $view->out( $view->headline );?></div>
<div id="panel">
<fieldset class="options">

    <legend>User Profile</legend>

    <TABLE class="management">

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
<?php endif;?>
</div>