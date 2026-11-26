<?php /** @var \OWA\Core\ViewScope $view */ ?>
<p><?php $view->out( \OWA\Core\CoreAPI::t( 'An application password was made for your Open Web Analytics account.' ) ); ?></p>

<p><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Name: %s' ), $view->name ) ); ?><br>
<?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Access: %s' ), $view->scope ) ); ?><br>
<?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Made: %s' ), $view->created ) ); ?><br>
<?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'From: %s' ), $view->from !== '' ? $view->from : \OWA\Core\CoreAPI::t( 'the command line on the server' ) ) ); ?></p>

<p><?php $view->out( \OWA\Core\CoreAPI::t( 'If you did not make it, revoke it here. You do not need to sign in:' ) ); ?></p>

<?php echo $view->makeAbsoluteLink( [ 'do' => 'base.appPasswordRevokeLink', 'id' => $view->lookup_id, 'key' => $view->key ] ); ?>
