<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div id="owa_header" data-ui="shell:header">

    <?php
    /*
     * The slide-out menu's button, on a screen that has one (the report and
     * settings views set nav_rail). Below 900px it takes the logo's place and
     * the logo moves to the top of the slide-out (logo_image.php); at full
     * width it is hidden (owa.report.css, owa.reportnav.js).
     */
    ?>
    <?php if ( $view->get( 'nav_rail' ) ):?>
    <button type="button" class="owa_reportNavToggle" aria-controls="owa_reportNavRail" aria-expanded="false"
            aria-label="<?php $view->out( $view->get( 'nav_section' ) === 'analytics' ? \OWA\Core\CoreAPI::t( 'Show the report menu' ) : \OWA\Core\CoreAPI::t( 'Show the menu' ) );?>">
        <i class="fas fa-bars" aria-hidden="true"></i>
    </button>
    <?php endif;?>
    <span class="owa_logo"><?php include( 'logo_image.php' );?></span>
    <span class="owa_headerRule" aria-hidden="true"></span>
    <?php
    /*
     * The Property chooser, beside the logo, on screens that are about a
     * Property (the report and hierarchy views set site_hierarchy). On a narrow
     * screen owa.report.js moves it into the slide-out menu.
     */
    ?>
    <?php if ( $view->get( 'site_hierarchy' ) ):?>
    <div class="owa_headerSiteControl"><?php include( 'site_control.php' );?></div>
    <?php endif;?>
     &nbsp
    <?php
        /*
         * nav_header: a module adds entries here (see Core\Navigation). OWA
         * has none of its own: its sections and Settings are the rail
         * (nav_shell.php), and Documentation, Report a Bug and Donate are in
         * the help menu on the right, since each leaves the application.
         */
        $owa_header_links = \OWA\Core\Navigation::links( 'nav_header', array() );
    ?>
    <?php if ( $owa_header_links ):?>
    <span class="owa_navigation">
        <UL>
            <?php foreach ( $owa_header_links as $owa_link ):?>
            <?php
            /*
             * An icon, named by aria-label and a tooltip. An entry a module adds
             * without one shows its label, as every entry used to.
             */
            ?>
            <?php if ( ! empty( $owa_link['icon'] ) ):?>
            <?php $owa_is_current = $owa_link['id'] === (string) $view->get( 'nav_section' ); ?>
            <LI><a class="owa_navIcon<?php echo $owa_is_current ? ' is-current' : '';?>"<?php echo $owa_is_current ? ' aria-current="page"' : '';?> href="<?php $view->safeHref( $owa_link['href'] );?>" aria-label="<?php $view->out( $owa_link['label'] );?>" title="<?php $view->out( $owa_link['label'] );?>"><i class="<?php $view->out( $owa_link['icon'] );?>" aria-hidden="true"></i></a></LI>
            <?php else:?>
            <LI><a href="<?php $view->safeHref( $owa_link['href'] );?>"><?php $view->out( $owa_link['label'] );?></a></LI>
            <?php endif;?>
            <?php endforeach;?>
        </UL>
    </span>
    <?php endif;?>
    <?php $cu = $view->getCurrentUser(); ?>
<?php if ( \OWA\Core\CoreAPI::isCurrentUserAuthenticated() ): ?>
    <?php
        /*
         * THE ACCOUNT MENU, at the right-hand end.
         *
         * FIRST in source order, because these are floated right and the first
         * element lands furthest right. Moving it after the bell would put the
         * name to the bell's left again without anything saying so.
         */
    ?>
    <span class="owa_userMenu">
        <button type="button" id="owa_userMenuToggle" class="owa_userMenuToggle"
                aria-expanded="false" aria-controls="owa_userMenuPanel" aria-haspopup="true"
                aria-label="<?php $view->out( sprintf( \OWA\Core\CoreAPI::t( 'Account: %s' ), $cu->getUserData('user_id') ) );?>">
            <i class="fas fa-user-circle owa_userMenuIcon" aria-hidden="true"></i>
            <span class="owa_userMenuName"><?php $view->out( $cu->getUserData('user_id') );?></span>
            <i class="fas fa-chevron-down owa_userMenuCaret" aria-hidden="true"></i>
        </button>
        <div id="owa_userMenuPanel" class="owa_userMenuPanel" role="menu" hidden>
            <?php
                /* nav_user_menu. */
                $owa_user_links = array(
                    array( 'id' => 'profile', 'label' => \OWA\Core\CoreAPI::t( 'Profile' ), 'class' => 'owa_myProfileLink',
                           'href' => $view->makeLink( array( 'do' => 'base.myProfile' ), false ) ),
                    array( 'id' => 'logout', 'label' => \OWA\Core\CoreAPI::t( 'Logout' ), 'class' => 'owa_userMenuLogout',
                           'href' => $view->makeLink( array( 'do' => 'base.logout' ), false, '', false, true ) ),
                );
            ?>
            <?php foreach ( \OWA\Core\Navigation::links( 'nav_user_menu', $owa_user_links ) as $owa_link ):?>
            <a role="menuitem" class="owa_userMenuItem <?php $view->out( $owa_link['class'] ?? '' );?>"
               href="<?php $view->safeHref( $owa_link['href'] );?>"><?php $view->out( $owa_link['label'] );?></a>
            <?php endforeach;?>
        </div>
    </span>
<?php
    /*
     * The badge count is NOT rendered here and is not a call of its own: the
     * bell's code (owa.header.js) counts the rows its one fetch returns.
     *
     * makeApiLink() builds the endpoint AND mints the nonce, so this does not
     * hard-code where the API lives or how a nonce is derived. The routes are
     * all named `notifications` and differ by HTTP method, and a nonce is
     * minted for one method: a URL each for the read, the mark-read and the
     * dismiss.
     */
    $owa_notificationRoute = array(
        'do'      => 'notifications',
        'module'  => 'base',
        'version' => 'v1',
    );
    $owa_apiUrl      = $view->makeApiLink( $owa_notificationRoute );
    $owa_markReadUrl = $view->makeApiLink( $owa_notificationRoute, false, 'POST' );
    $owa_dismissUrl  = $view->makeApiLink( $owa_notificationRoute, false, 'DELETE' );
?>
    <span class="owa_notificationBell" data-ui="header:notifications"
          data-owa-notifications-read="<?php $view->out( $owa_apiUrl ); ?>"
          data-owa-notifications-mark-read="<?php $view->out( $owa_markReadUrl ); ?>"
          data-owa-notifications-dismiss="<?php $view->out( $owa_dismissUrl ); ?>">
        <button type="button" id="owa_notificationToggle" class="owa_notificationToggle"
                aria-expanded="false" aria-controls="owa_notificationPanel"
                aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Notifications' ) ); ?>">
            <i class="fas fa-bell"></i>
            <span id="owa_notificationBadge" class="owa_notificationBadge">0</span>
        </button>
        <div id="owa_notificationPanel" class="owa_notificationPanel" hidden>
            <div class="owa_notificationPanelHeader"><?php $view->out( \OWA\Core\CoreAPI::t( 'Notifications' ) ); ?></div>
            <div id="owa_notificationItems" class="owa_notificationItems">
                <p class="info_text"><?php $view->out( \OWA\Core\CoreAPI::t( 'Loading...' ) ); ?></p>
            </div>
        </div>
    </span>
<?php endif; ?>
    <?php
        /*
         * THE ACCOUNT MENU.
         *
         * This was "Hi, <name> ! * Logout" -- a greeting, a bullet and a link,
         * which spent the widest part of the bar saying hello and left the two
         * things people come here to do looking like punctuation.
         *
         * The name is now the control: a button that opens a menu holding
         * Profile and Logout. Same interaction as the notification bell beside
         * it -- a button with aria-expanded, a panel with hidden, and a click
         * outside to close -- so the two behave alike.
         */
    ?>
<?php if ( ! \OWA\Core\CoreAPI::isCurrentUserAuthenticated() ): ?>
    <?php
        /*
         * Signed out, the account slot is a way in rather than a menu. Before
         * the help block so it takes the same place at the right-hand end that
         * the account menu does when there is one.
         */
    ?>
    <span class="owa_userMenu">
        <a class="owa_userMenuSignIn" href="<?php echo $view->makeLink(array('do' => 'base.loginForm'), false);?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Login' ) ); ?></a>
    </span>
<?php endif; ?>
    <?php
        /*
         * HELP, at the left of the group, AND FOR EVERYONE.
         *
         * Outside the signed-in branch on purpose. An install that grants
         * "everyone" view_reports -- which the demo does -- renders this chrome
         * for people with no account, and documentation is the thing they are
         * most likely to want. Every destination here is a public GitHub URL,
         * so there is nothing in it that depends on being signed in.
         *
         * Last in source order, which is what puts it at the left: these are
         * floated right, so the first element in source order lands furthest
         * right and the bar reads help, notifications, account.
         */
    ?>
    <span class="owa_helpMenu">
        <button type="button" id="owa_helpMenuToggle" class="owa_helpMenuToggle"
                aria-expanded="false" aria-controls="owa_helpMenuPanel"
                aria-haspopup="true" aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Help' ) ); ?>">
            <i class="fas fa-question" aria-hidden="true"></i>
        </button>
        <div id="owa_helpMenuPanel" class="owa_helpMenuPanel owa_headerMenuPanel" role="menu" hidden>
            <?php foreach ( \OWA\Core\Navigation::links( 'nav_help_menu', array(
                array( 'id' => 'documentation', 'label' => \OWA\Core\CoreAPI::t( 'Documentation' ),
                       'href' => 'https://github.com/Open-Web-Analytics/Open-Web-Analytics/wiki' ),
                array( 'id' => 'bugs', 'label' => \OWA\Core\CoreAPI::t( 'Report a Bug' ),
                       'href' => 'https://github.com/Open-Web-Analytics/Open-Web-Analytics/issues' ),
                array( 'id' => 'github', 'label' => 'GitHub',
                       'href' => 'https://github.com/Open-Web-Analytics/Open-Web-Analytics' ),
                array( 'id' => 'donate', 'label' => \OWA\Core\CoreAPI::t( 'Donate' ),
                       'href' => 'https://github.com/sponsors/padams' ),
            ) ) as $owa_link ):?>
            <a role="menuitem" class="owa_headerMenuItem" target="_blank" rel="noopener"
               href="<?php $view->safeHref( $owa_link['href'] );?>"><?php $view->out( $owa_link['label'] );?></a>
            <?php endforeach;?>
        </div>
    </span>
    <div class="post-nav"></div>
    <?php if (!empty($view->service_msg)): ?>
    <div class="owa_headerServiceMsg"><?php echo $view->service_msg; ?></div>
    <?php endif;?>

    <?php $view->headerActions(); ?>

</div>