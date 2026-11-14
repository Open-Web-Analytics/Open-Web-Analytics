<?php /** @var \OWA\Core\ViewScope $view */ ?>
<h2><?php $view->out( $view->settings_page_title ); ?></h2>

<?php $view->out( \OWA\Core\CoreAPI::t( 'Hello world. This is how you create a settings page.' ) ); ?>