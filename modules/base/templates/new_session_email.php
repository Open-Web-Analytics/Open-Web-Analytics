<p>There was a new visit to site: <?php $this->out( $site['domain'] );?>.</p>

<p>Visitor ID: <?php $this->out( $session['visitor_id'] );?></p>

<p>Username (email): <?php $this->out( $session['user_name'] );?>  (<?php if (isset($session['user_email'])) {
                                                                    $this->out( $session['user_email'] );
                                                                } else {
                                                                    echo 'not set';
                                                                }  ?>)
</p>
<p>Host: <?php $this->out( $session['host'] );?></p>


<p>City/Country:  <?php $this->out( $session['city'] );?> <?php $this->out( $session['country'] );?></p>


<p>Entry page:  <?php $this->out( $session['page_title'] );?> - <?php $this->out( $session['page_url'] );?></p>


<hr>
<p>This visit notification e-mail was sent to you from your instance of Open Web Analytics running at <?php echo owa_coreAPI::getSetting('base', 'public_url'); ?>. To disable these notifications change your configuration settings.</p>