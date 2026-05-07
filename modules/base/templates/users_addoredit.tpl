<div class="panel_headline"><?php $this->out( $headline );?></div>
<div id="panel">
<fieldset class="options">

    <legend>User Profile</legend>

    <TABLE class="management">

        <form method="POST">
        <TR>
            <TH>User Name</TH>
            <TD>
            <?php if ( $edit === true ):?>
            <input type="hidden" size="30" name="<?php echo $this->getNs();?>user_id" value="<?php $this->out( $user['user_id'] );?>"><span class="noedit"><?php $this->out( $user['user_id'] )?></span>
            <?php else:?>
            <input type="text" size="30" name="<?php echo $this->getNs();?>user_id" value="<?php $this->out( @$user['user_id'] );?>">
            <?php endif;?>
            </TD>
        </TR>

        <?php if ($edit === true):?>
        <TR>
            <TH>API Key</TH>
            <TD><span class="noedit"><?php echo $user['api_key'];?></span></TD>
        </TR>
        <?php endif;?>

        <?php if ( ! $isAdmin ):?>
        <TR>
            <TH>Real Name</TH>
            <TD><input type="text" size="30" name="<?php echo $this->getNs();?>real_name" value="<?php $this->out( $this->getValue( 'real_name', $user ) );?>"></TD>
        </TR>
        <TR>
            <TH>Role</TH>
            <TD>
            <select name="<?php echo $this->getNs();?>role">
                <?php foreach ($roles as $role):?>
                <option <?php if( isset( $user['role'] ) && $user['role'] === $role): echo "SELECTED"; endif;?> value="<?php echo $role;?>"><?php echo $role;?></option>
                <?php endforeach;?>
            </select>
            </TD>
        </TR>


        <?php endif;?>
        <TR>
            <TH>E-mail Address</TH>
            <TD><input type="text"size="30" name="<?php echo $this->getNs();?>email_address" value="<?php $this->out( @$user['email_address'] );?>"></TD>
        </TR>

        <TR>
            <TD>
                <input type="hidden" name="<?php echo $this->getNs();?>id" value="<?php $this->out( @$user['id'] );?>">
                <?php echo $this->createNonceFormField($action);?>
                <input type="hidden" name="<?php echo $this->getNs();?>action" value="<?php echo $action;?>">
                <input class="owa-button" type="submit" value="Save" name="<?php echo $this->getNs();?>save_button">
            </TD>
        </TR>
        </form>

    </TABLE>

</fieldset>
<?php if ($edit === true):?>
<P>
<fieldset class="options">

    <legend>Change Password</legend>
    <div style="padding:10px">
    <a href="<?php echo $this->makeLink(array('do' => 'base.passwordResetForm'))?>">Change password for this user</a>
    </div>
</fieldset>
<?php endif;?>
</div>