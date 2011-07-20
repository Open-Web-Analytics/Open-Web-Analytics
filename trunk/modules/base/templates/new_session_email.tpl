There was a new visit to <?php echo $site['domain'];?> from:

Visitor ID: <?php echo $session['visitor_id'];?>


Username (email): <?php echo $session['user_name'];?>  (<?php if (isset($session['user_email'])) { 
																	echo $session['user_email']; 
																} else {
																	echo 'not set';
																}  ?>)

Host: <?php echo $session['host'];?>


City/Country:  <?php echo $session['city'];?> <?php echo $session['country'];?>


Entry page:  <?php echo $session['page_title'];?> - <?php echo $session['page_url'];?>


--
This visit notification e-mail was sent to you from your instance of Open Web Analytics running at <?php echo owa_coreAPI::getSetting('base', 'public_url'); ?>. To disable these notifications change your configuration settings.