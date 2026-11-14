<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php // Plain text: not HTML-escaped. ?>
<?php echo sprintf( \OWA\Core\CoreAPI::t( 'New Visit to %s from:' ), $view->site['domain'] );?>


<?php echo sprintf( \OWA\Core\CoreAPI::t( 'Visitor: %s' ), $view->session['visitor_id'] );?>

<?php echo sprintf( \OWA\Core\CoreAPI::t( 'Email or Username: %1$s | %2$s' ), $view->session['user_email'], $view->session['user_name'] );?>

<?php echo sprintf( \OWA\Core\CoreAPI::t( 'Host: %s' ), $view->session['host'] );?>

<?php echo sprintf( \OWA\Core\CoreAPI::t( 'City/Country: %1$s %2$s' ), $view->session['city'], $view->session['country'] );?>

<?php echo sprintf( \OWA\Core\CoreAPI::t( 'Entry page: %1$s (%2$s)' ), $view->session['page_title'], $view->session['page_url'] );?>
