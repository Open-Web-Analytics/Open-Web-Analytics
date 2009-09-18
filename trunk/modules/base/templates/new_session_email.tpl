 
<H1>New Visit to <?php echo $site['domain'];?> from:</H1>

 Visitor: <?php echo $session['visitor_id'];?><BR>
 Email or Username: <?php echo $session['user_email'];?> | <?php echo $session['user_name'];?><BR>
 Host: <?php echo $session['host'];?><BR>
 City/Country:  <?php echo $session['city'];?> <?php echo $session['country'];?><BR>
 Entry page:  <?php echo $session['page_title'];?> (<?php echo $session['page_url'];?>)<BR>
