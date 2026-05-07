<p>An Open Web Analytics account has been created for you.</p>

<p>Your User Name is: <?php $this->out( $user_id );?></p> 

<p>To login you need to set your password by clicking on the link below.</p>

<p><?php echo $this->makeAbsoluteLink(array('do' => 'base.usersPasswordEntry', 'k' => $key));?> </p>

<p>Once your password has been setup you can login to OWA at the following URL:</p>

<p><?php echo $this->makeAbsoluteLink(array('do' => 'base.reportDashboard'));?></p> 