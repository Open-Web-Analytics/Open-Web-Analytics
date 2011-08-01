<p>There was a new visit to site: <?php echo $site['domain'];?>.</p>

<p>Visitor ID: <?php echo $session['visitor_id'];?></p>

<p>Username (email): <?php echo $session['user_name'];?>  (<?php if (isset($session['user_email'])) { 
																	echo $session['user_email']; 
																} else {
																	echo 'not set';
																}  ?>)
</p>
<p>Host: <?php echo $session['host'];?></p>


<p>City/Country:  <?php echo $session['city'];?> <?php echo $session['country'];?></p>


<p>Entry page:  <?php echo $session['page_title'];?> - <?php echo $session['page_url'];?></p>


<hr>
<p>This visit notification e-mail was sent to you from your instance of Open Web Analytics running at <?php echo owa_coreAPI::getSetting('base', 'public_url'); ?>. To disable these notifications change your configuration settings.</p>