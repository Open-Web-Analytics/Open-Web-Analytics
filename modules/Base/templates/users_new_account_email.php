<?php /** @var \OWA\Core\ViewScope $view */ ?>
<p><?php $view->out( \OWA\Core\CoreAPI::t( 'An Open Web Analytics account has been created for you.' ) );?></p>

<p><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Your User Name is: %s' ), $view->user_id ) );?></p> 

<?php $owa_inviteDays = (int) ( \OWA\Module\Base\Entity\User::INVITE_TTL / 86400 );?>
<p><?php $view->out( sprintf( \OWA\Core\CoreAPI::tn( 'To login you need to set your password by clicking on the link below. The link works for %d day.', 'To login you need to set your password by clicking on the link below. The link works for %d days.', $owa_inviteDays ), $owa_inviteDays ) );?></p>

<p><?php echo $view->makeAbsoluteLink(array('do' => 'base.usersPasswordEntry', 'k' => $view->key));?> </p>

<p><?php $view->out( \OWA\Core\CoreAPI::t( 'Once your password has been setup you can login to OWA at the following URL:' ) );?></p>

<p><?php echo $view->makeAbsoluteLink(array('do' => 'base.report', 'reportId' => 'dashboard'));?></p> 