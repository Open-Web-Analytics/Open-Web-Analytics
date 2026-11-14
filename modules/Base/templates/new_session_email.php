<?php /** @var \OWA\Core\ViewScope $view */ ?>
<p><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'There was a new visit to site: %s.' ), $view->site['domain'] ) );?></p>

<p><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Visitor ID: %s' ), $view->session['visitor_id'] ) );?></p>

<p><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Username (email): %1$s (%2$s)' ),
                              $view->session['user_name'],
                              isset( $view->session['user_email'] ) ? $view->session['user_email'] : \OWA\Core\CoreAPI::t( 'not set' ) ) );?>
</p>
<p><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Host: %s' ), $view->session['host'] ) );?></p>


<p><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'City/Country: %1$s %2$s' ), $view->session['city'], $view->session['country'] ) );?></p>


<p><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Entry page: %1$s - %2$s' ), $view->session['page_title'], $view->session['page_url'] ) );?></p>


<hr>
<p><?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'This visit notification e-mail was sent to you from your instance of Open Web Analytics running at %s. To disable these notifications change your configuration settings.' ), \OWA\Core\CoreAPI::getSetting('base', 'public_url') ) );?></p>