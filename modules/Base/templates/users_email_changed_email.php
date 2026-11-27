<?php /** @var \OWA\Core\ViewScope $view */ ?>
<p><?php $view->out( \OWA\Core\CoreAPI::t( 'The email address of your Open Web Analytics account was changed. Mail for the account, password resets included, now goes to the new address.' ) ); ?></p>

<p><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'User name: %s' ), $view->user_id ) ); ?><br>
<?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'New address: %s' ), $view->new_email_address ) ); ?><br>
<?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Changed: %s' ), $view->changed ) ); ?><br>
<?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'From: %s' ), $view->ip ) ); ?></p>

<p><?php $view->out( \OWA\Core\CoreAPI::t( 'If you did not change it, ask an administrator of this Open Web Analytics installation to restore this address on the Users screen.' ) ); ?></p>
