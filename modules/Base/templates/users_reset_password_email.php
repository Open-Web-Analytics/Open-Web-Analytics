<?php /** @var \OWA\Core\ViewScope $view */ ?>
<P><?php $view->out( \OWA\Core\CoreAPI::t( 'Someone, hopefully you, has requested a reset of your Open Web Analytics account password.' ) ); ?></P>

<p><?php $view->out( \OWA\Core\CoreAPI::t( 'If this message was generated in error, please disregard. If not, please click on the link below to complete the process.' ) );?>
<?php $owa_hours = (int) ( \OWA\Module\Base\Entity\User::RESET_TTL / 3600 );
      $view->out( sprintf( \OWA\Core\CoreAPI::tn( 'The link works for %d hour.', 'The link works for %d hours.', $owa_hours ), $owa_hours ) );?></p>

<?php echo $view->makeAbsoluteLink(array('do' => 'base.usersPasswordEntry', 'k' => $view->key));
