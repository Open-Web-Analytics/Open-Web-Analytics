<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The signed-in user's own account.
 *
 * Laid out with the .setting / .title / .description / .field markup the rest
 * of the settings screens use, so it reads as one of them rather than as a
 * screen of its own.
 */
$owa_mayEditEmail = (bool) $view->may_edit_email;
$owa_error        = (string) $view->my_profile_error;
$owa_minPassword  = (int) $view->min_password_length;
?>
<DIV class="panel_headline">User Profile</DIV>
<div id="panel">

<?php if ( $owa_error ): ?>
<div class="notice error" role="alert"><?php $view->out( $owa_error ); ?></div>
<?php endif; ?>

<?php if ( $view->my_profile_saved ): ?>
<div class="notice" role="status">Your profile has been saved.</div>
<?php endif; ?>

<form method="post" name="owa_myProfile"
      action="<?php echo $view->makeLink( array( 'do' => 'base.myProfileSave' ) ); ?>">

    <fieldset name="owa-options" class="options">

        <div class="setting">
            <div class="title">Username</div>
            <div class="field">
                <span class="noedit"><?php $view->out( $view->user_id ); ?></span>
            </div>
        </div>

        <div class="setting">
            <div class="title">Role</div>
            <div class="field">
                <span class="noedit"><?php $view->out( $view->role ); ?></span>
            </div>
        </div>

        <div class="setting">
            <div class="title">Name</div>
            <div class="description">Your name, as other people see it on the users
            list.</div>
            <div class="field">
                <input type="text" size="30" maxlength="255" name="real_name"
                       value="<?php $view->out( $view->real_name ); ?>">
            </div>
        </div>

        <div class="setting">
            <div class="title">Email address</div>
            <div class="description">Where password resets are sent.<?php
                if ( ! $owa_mayEditEmail ): ?> Changing it is not something your role can
                do &mdash; ask an administrator.<?php endif; ?></div>
            <div class="field">
                <?php
                    /*
                     * Shown either way. Which address the account uses is worth
                     * knowing even when it cannot be changed here, and a field
                     * that vanishes reads as an account with no address.
                     *
                     * Read-only WITHOUT a name attribute, so nothing is posted
                     * at all -- a disabled input posts nothing either, but a
                     * readonly one does, and posting the unchanged value would
                     * make the save compare a value it should never have been
                     * offered.
                     */
                ?>
                <?php if ( $owa_mayEditEmail ): ?>
                <input type="email" size="30" maxlength="255" name="email_address"
                       value="<?php $view->out( $view->email_address ); ?>">
                <?php else: ?>
                <span class="noedit"><?php $view->out( $view->email_address ); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="setting">
            <div class="title">Change password</div>
            <div class="description">Leave empty to keep your current password. At least
            <?php echo (int) $owa_minPassword; ?> characters. Changing it signs you out.</div>
            <div class="field">
                <?php
                    /*
                     * autocomplete names the browser understands, so a password
                     * manager offers the right thing in each box rather than
                     * filling the new-password fields with the old one.
                     */
                ?>
                <div class="owa_passwordFields">
                    <input type="password" size="30" name="current_password"
                           autocomplete="current-password" placeholder="Current password">
                    <input type="password" size="30" name="new_password"
                           autocomplete="new-password" placeholder="New password">
                    <input type="password" size="30" name="new_password2"
                           autocomplete="new-password" placeholder="Repeat new password">
                </div>
            </div>
        </div>

        <BR>

        <?php echo $view->createNonceFormField( 'base.myProfileSave' ); ?>
        <?php
            /*
             * The site travels with the form so the redirect back lands on a
             * screen that names one -- the settings chrome needs a Profile to
             * draw its tile and its nav.
             */
        ?>
        <input type="hidden" name="siteId" value="<?php $view->out( $view->siteId ); ?>">
        <input class="owa-button" type="submit" value="Save Profile">
    </fieldset>
</form>
</div>
