<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_publicCard" data-ui="app-password-revoke:<?php $view->out( $view->outcome ); ?>">

    <h1 class="owa_publicTitle"><?php $view->out( \OWA\Core\CoreAPI::t( 'Revoke an application password' ) ); ?></h1>

    <?php if ( $view->outcome === 'ask' ): ?>

    <p class="owa_publicIntro"><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Revoke %s? Anything using it stops working immediately.' ), $view->name ) ); ?></p>

    <form method="POST" class="owa_publicForm" action="<?php echo $view->makeLink( [ 'do' => 'base.appPasswordRevokeLink' ] ); ?>">
        <input type="hidden" name="id" value="<?php $view->out( $view->id ); ?>">
        <input type="hidden" name="key" value="<?php $view->out( $view->key ); ?>">
        <input class="owa-button owa_publicSubmit" type="submit" value="<?php $view->out( \OWA\Core\CoreAPI::t( 'Revoke' ) ); ?>">
    </form>

    <?php elseif ( $view->outcome === 'revoked' ): ?>

    <p class="owa_publicIntro" role="status"><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( '%s is revoked.' ), $view->name ) ); ?></p>
    <p><?php $view->out( \OWA\Core\CoreAPI::t( 'If you did not make it, someone else was signed in to your account: change your password.' ) ); ?></p>

    <?php else: ?>

    <p class="owa_publicIntro" role="status"><?php $view->out( \OWA\Core\CoreAPI::t( 'This link revokes nothing: the application password is already revoked, or the link is not whole.' ) ); ?></p>

    <?php endif; ?>

    <div class="owa_publicAside">
        <a href="<?php echo $view->makeLink( [ 'do' => 'base.loginForm' ] ); ?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Sign in' ) ); ?></a>
    </div>
</div>
