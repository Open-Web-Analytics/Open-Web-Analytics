<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php $view->outMarkup( \OWA\Core\CoreAPI::t( 'Your Open Web Analytics password was successfully changed on %1$s from IP address %2$s.' ), date("F j, Y, \a\\t g:i a"), htmlspecialchars( (string) $view->ip, ENT_QUOTES, 'UTF-8' ) );?>
