<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_publicCard">

    <h1 class="owa_publicTitle"><?php $view->out( \OWA\Core\CoreAPI::t( 'Do you want to log out?' ) );?></h1>

    <form method="POST" class="owa_publicForm">

        <input type="hidden" name="action" value="base.logout">
        <?php echo $view->createNonceFormField( 'base.logout' );?>

        <input class="owa-button owa_publicSubmit" type="submit"
               name="submit_btn" value="<?php $view->out( \OWA\Core\CoreAPI::t( 'Log out' ) );?>">
    </form>

    <div class="owa_publicAside">
        <?php if ( $view->back_url ):?>
        <a href="<?php $view->out( $view->back_url );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Return to the previous screen' ) );?></a>
        <?php else:?>
        <a href="<?php echo $view->makeLink( [ 'do' => \OWA\Core\CoreAPI::getSetting( 'base', 'start_page' ) ] );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Stay signed in' ) );?></a>
        <?php endif;?>
    </div>
</div>
