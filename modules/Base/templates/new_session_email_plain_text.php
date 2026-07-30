<?php /** @var \OWA\Core\ViewScope $view */ ?>
New Visit to <?php echo $view->site['domain'];?> from:

Visitor: <?php echo $view->session['visitor_id'];?>
Email or Username: <?php echo $view->session['user_email'];?> | <?php echo $view->session['user_name'];?>
Host: <?php echo $view->session['host'];?>
City/Country:  <?php echo $view->session['city'];?> <?php echo $view->session['country'];?>
Entry page:  <?php echo $view->session['page_title'];?> (<?php echo $view->session['page_url'];?>)
