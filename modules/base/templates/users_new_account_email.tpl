An Open Web Analytics account has been created for you.

Your User Name is: <?php echo $user_id;?> 

To login you need to set your password by clicking on the link below.

<?php echo $this->makeAbsoluteLink(array('do' => 'base.usersPasswordEntry', 'k' => $key));?> 

Once your password has been setup you can login to OWA at the following URL:

<?php echo $this->makeAbsoluteLink(array('do' => 'base.reportDashboard'));?> 