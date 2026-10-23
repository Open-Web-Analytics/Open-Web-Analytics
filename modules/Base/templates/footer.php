<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The version for a signed-in user only. This footer is also drawn on screens
 * a signed-out visitor can reach, and the version tells a stranger which
 * advisories an install is open to.
 */
?>
<div class="owa_footer">
    <?php if ( $view->getCurrentUser()->isAuthenticated() ):?>
    <?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Open Web Analytics version %s' ), OWA_VERSION ) );?>
    <?php else:?>
    Open Web Analytics
    <?php endif;?>
</div>
