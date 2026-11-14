<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The error page's body. The message itself is in the wrapper's error banner
 * (View\Error); this carries only the way back, when there is one.
 */
?>
<?php if ( $view->back_url ):?>
<p class="owa_errorBack"><a href="<?php $view->out( $view->back_url );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Return to the previous screen' ) );?></a></p>
<?php endif;?>
