<?php /** @var \OWA\Core\ViewScope $view */ ?>
<P>Someone, hopefully you, has requested a reset of your Open Web Analytics account password.</P>

<p>If this message was generated in error, please disregard. If not, please click on the link below
to complete the process.</p>

<?php echo $view->makeAbsoluteLink(array('do' => 'base.usersPasswordEntry', 'k' => $view->key));
