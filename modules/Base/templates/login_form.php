<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_publicCard">

    <h1 class="owa_publicTitle"><?php $view->out( \OWA\Core\CoreAPI::t( 'Login' ) ); ?></h1>

    <form method="POST" class="owa_publicForm">

        <div class="owa_publicField">
            <label for="owa_loginUserId"><?php $view->out( \OWA\Core\CoreAPI::t( 'User name' ) ); ?></label>
            <input id="owa_loginUserId" type="text" autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" autofocus
                   name="user_id"
                   value="<?php $view->out( $view->user_id ); ?>">
        </div>

        <div class="owa_publicField">
            <label for="owa_loginPassword"><?php $view->out( \OWA\Core\CoreAPI::t( 'Password' ) ); ?></label>
            <input id="owa_loginPassword" type="password" autocomplete="current-password"
                   name="password">
        </div>

        <?php
            /*
             * Where to go once they are in, and which action handles this. Both
             * were here before and both are load-bearing: without `go` a login
             * from a deep link lands on the dashboard instead.
             */
        ?>
        <input type="hidden" name="go" value="<?php $view->out( $view->go );?>">
        <input type="hidden" name="action" value="base.login">

        <input class="owa-button owa_publicSubmit" type="submit"
               name="submit_btn" value="<?php $view->out( \OWA\Core\CoreAPI::t( 'Login' ) ); ?>">
    </form>

    <div class="owa_publicAside">
        <a href="<?php echo $view->makeLink(array('do' => 'base.passwordResetForm'))?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Forgot your password?' ) ); ?></a>
    </div>
</div>
