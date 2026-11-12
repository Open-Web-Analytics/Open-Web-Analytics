<?php /** @var \OWA\Core\ViewScope $view */ ?>
<P>Someone, hopefully you, has requested a reset of your Open Web Analytics account password.</P>

<p>If this message was generated in error, please disregard. If not, please click on the link below
to complete the process. The link works for <?php echo (int) ( \OWA\Module\Base\Entity\User::RESET_TTL / 3600 );?> hours.</p>

<?php echo $view->makeAbsoluteLink(array('do' => 'base.usersPasswordEntry', 'k' => $view->key));
