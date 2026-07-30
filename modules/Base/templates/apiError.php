<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php if (isset($view->error_msg['headline'])) : $view->out( $view->error_msg['headline'] ); endif; ?>
<?php if (isset($view->error_msg['message'])) : $view->out( $view->error_msg['message'] ); endif; ?>