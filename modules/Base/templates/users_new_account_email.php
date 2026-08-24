<?php /** @var \OWA\Core\ViewScope $view */ ?>
<p>An Open Web Analytics account has been created for you.</p>

<p>Your User Name is: <?php $view->out( $view->user_id );?></p> 

<p>To login you need to set your password by clicking on the link below.</p>

<p><?php echo $view->makeAbsoluteLink(array('do' => 'base.usersPasswordEntry', 'k' => $view->key));?> </p>

<p>Once your password has been setup you can login to OWA at the following URL:</p>

<p><?php echo $view->makeAbsoluteLink(array('do' => 'base.report', 'reportId' => 'dashboard'));?></p> 