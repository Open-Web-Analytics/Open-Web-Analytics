<div id="owa_header">

    <span class="owa_logo"><img src="<?php echo $this->makeImageLink( owa_coreAPI::getSetting( 'base', 'logo_image_path' ) ); ?>" alt="Open Web Analytics"></span>
     &nbsp
    <span class="owa_navigation">
        <UL>
            <?php if ($this->getCurrentUser()->isCapable('view_site_list')): ?>
                <LI><a href="<?php echo $this->makeLink(array('do' => 'base.sites'));?>">Reporting</a></LI>
            <?php endif; ?>
            <?php if ($this->getCurrentUser()->isCapable('edit_settings')): ?>
                <LI><a href="<?php echo $this->makeLink(array('do' => 'base.optionsGeneral'));?>">Settings</a></LI>
            <?php endif; ?>
            <LI><a href="https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki">Documentation</a></LI>
            <LI><a href="https://github.com/Open-Web-Analytics/Open-Web-Analytics/issues">Report a Bug</a></LI>
            <LI><a href="https://github.com/sponsors/padams">Donate</a>

        </UL>
    </span>
    <?php $cu = $this->getCurrentUser(); ?>
    <span class="user-greating" style="">
        Hi, <?php $this->out( $cu->getUserData('user_id') );?> ! &bull;
        <?php if ( ! owa_coreAPI::getSetting( 'base', 'is_embedded' ) ):?>

                <?php if ( owa_coreAPI::isCurrentUserAuthenticated() ):?>
                <a class="login" href="<?php echo $this->makeLink(array('do' => 'base.logout'), false);?>">Logout</a>
                <?php else:?>
                <a class="login" href="<?php echo $this->makeLink(array('do' => 'base.loginForm'), false);?>">Login</a>
                <?php endif;?>

            <?php endif;?>
    </span>
    <div class="post-nav"></div>
    <?php if (!empty($service_msg)): ?>
    <div class="owa_headerServiceMsg"><?php echo $service_msg; ?></div>
    <?php endif;?>

    <?php $this->headerActions(); ?>

</div>