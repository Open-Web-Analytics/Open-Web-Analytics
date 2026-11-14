<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_publicCard">

    <h1 class="owa_publicTitle">Set your password</h1>

    <p class="owa_publicIntro">Choose a new password for your account.</p>

    <form method="POST" class="owa_publicForm">

        <div class="owa_publicField">
            <label for="owa_newPassword">New password</label>
            <input id="owa_newPassword" type="password" autocomplete="new-password" autofocus
                   name="password">
        </div>

        <div class="owa_publicField">
            <label for="owa_newPassword2">Repeat new password</label>
            <input id="owa_newPassword2" type="password" autocomplete="new-password"
                   name="password2">
        </div>

        <?php
            /*
             * The passkey from the emailed link is what identifies the account
             * here -- there is no session yet -- so it has to travel with the
             * form.
             */
        ?>
        <input type="hidden" name="k" value="<?php $view->out( $view->key );?>">
        <input type="hidden" name="action" value="base.usersChangePassword">

        <input class="owa-button owa_publicSubmit" type="submit"
               name="submit_btn" value="Save new password">
    </form>
</div>
