<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_publicCard">

    <h1 class="owa_publicTitle">Login</h1>

    <form method="POST" class="owa_publicForm">

        <div class="owa_publicField">
            <label for="owa_loginUserId">User name</label>
            <input id="owa_loginUserId" type="text" autocomplete="username" autofocus
                   name="<?php echo $view->getNs();?>user_id"
                   value="<?php $view->out( $view->user_id ); ?>">
        </div>

        <div class="owa_publicField">
            <label for="owa_loginPassword">Password</label>
            <input id="owa_loginPassword" type="password" autocomplete="current-password"
                   name="<?php echo $view->getNs();?>password">
        </div>

        <?php
            /*
             * Where to go once they are in, and which action handles this. Both
             * were here before and both are load-bearing: without `go` a login
             * from a deep link lands on the dashboard instead.
             */
        ?>
        <input type="hidden" name="<?php echo $view->getNs();?>go" value="<?php $view->out( $view->go );?>">
        <input type="hidden" name="<?php echo $view->getNs();?>action" value="base.login">

        <input class="owa-button owa_publicSubmit" type="submit"
               name="<?php echo $view->getNs();?>submit_btn" value="Login">
    </form>

    <div class="owa_publicAside">
        <a href="<?php echo $view->makeLink(array('do' => 'base.passwordResetForm'))?>">Forgot your password?</a>
    </div>
</div>
