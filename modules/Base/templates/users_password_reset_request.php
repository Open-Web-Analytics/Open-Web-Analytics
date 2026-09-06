<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_publicCard">

    <h1 class="owa_publicTitle">Reset your password</h1>

    <p class="owa_publicIntro">Enter the email address on your account and we will send you a
    link to set a new password.</p>

    <?php
        /*
         * The markup here used to close a table that was never opened --
         * </TD></TR>, a <TR>, then </TABLE> -- left behind when this stopped
         * being a table. Browsers dropped the stray tags silently.
         */
    ?>
    <form method="POST" class="owa_publicForm">

        <div class="owa_publicField">
            <label for="owa_resetEmail">Email address</label>
            <input id="owa_resetEmail" type="email" autocomplete="email" autofocus
                   name="<?php echo $view->getNs();?>email_address" value="">
        </div>

        <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.passwordResetRequest">

        <input class="owa-button owa_publicSubmit" type="submit"
               name="<?php echo $view->getNs();?>submit" value="Send reset link">
    </form>

    <div class="owa_publicAside">
        <a href="<?php echo $view->makeLink(array('do' => 'base.loginForm'))?>">Back to login</a>
    </div>
</div>
