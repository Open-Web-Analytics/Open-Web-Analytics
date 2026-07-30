<?php /** @var \OWA\Core\ViewScope $view */ ?>
<p>There was a new visit to site: <?php $view->out( $view->site['domain'] );?>.</p>

<p>Visitor ID: <?php $view->out( $view->session['visitor_id'] );?></p>

<p>Username (email): <?php $view->out( $view->session['user_name'] );?>  (<?php if (isset($view->session['user_email'])) {
                                                                    $view->out( $view->session['user_email'] );
                                                                } else {
                                                                    echo 'not set';
                                                                }  ?>)
</p>
<p>Host: <?php $view->out( $view->session['host'] );?></p>


<p>City/Country:  <?php $view->out( $view->session['city'] );?> <?php $view->out( $view->session['country'] );?></p>


<p>Entry page:  <?php $view->out( $view->session['page_title'] );?> - <?php $view->out( $view->session['page_url'] );?></p>


<hr>
<p>This visit notification e-mail was sent to you from your instance of Open Web Analytics running at <?php echo \OWA\Core\CoreAPI::getSetting('base', 'public_url'); ?>. To disable these notifications change your configuration settings.</p>