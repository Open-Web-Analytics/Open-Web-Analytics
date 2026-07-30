<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div id="owa_header">

    <span class="owa_logo"><img src="<?php echo $view->makeImageLink( \OWA\Core\CoreAPI::getSetting( 'base', 'logo_image_path' ) ); ?>" alt="Open Web Analytics"></span>
     &nbsp
    <span class="owa_navigation">
        <UL>
            <?php if ($view->getCurrentUser()->isCapable('view_site_list')): ?>
                <LI><a href="<?php echo $view->makeLink(array('do' => 'base.sites'));?>">Reporting</a></LI>
            <?php endif; ?>
            <?php if ($view->getCurrentUser()->isCapable('edit_settings')): ?>
                <LI><a href="<?php echo $view->makeLink(array('do' => 'base.optionsGeneral'));?>">Settings</a></LI>
            <?php endif; ?>
            <LI><a href="https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki">Documentation</a></LI>
            <LI><a href="https://github.com/Open-Web-Analytics/Open-Web-Analytics/issues">Report a Bug</a></LI>
            <LI><a href="https://github.com/sponsors/padams">Donate</a>

        </UL>
    </span>
    <?php $cu = $view->getCurrentUser(); ?>
    <span class="user-greating" style="">
        Hi, <?php $view->out( $cu->getUserData('user_id') );?> ! &bull;
        <?php if ( ! \OWA\Core\CoreAPI::getSetting( 'base', 'is_embedded' ) ):?>

                <?php if ( \OWA\Core\CoreAPI::isCurrentUserAuthenticated() ):?>
                <a class="login" href="<?php echo $view->makeLink(array('do' => 'base.logout'), false);?>">Logout</a>
                <?php else:?>
                <a class="login" href="<?php echo $view->makeLink(array('do' => 'base.loginForm'), false);?>">Login</a>
                <?php endif;?>

            <?php endif;?>
    </span>
    <div class="post-nav"></div>
    <?php if (!empty($service_msg)): ?>
    <div class="owa_headerServiceMsg"><?php echo $service_msg; ?></div>
    <?php endif;?>

    <?php $view->headerActions(); ?>

</div>