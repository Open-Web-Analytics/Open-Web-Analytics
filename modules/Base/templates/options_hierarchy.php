<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The wrapper for the Organization / Property / Profile edit screens.
 *
 * Same two columns as options.php, but the left one carries the SITE CONTROL
 * rather than the settings panels. These screens are reached from that control
 * and belong to the hierarchy, not to the install's settings -- putting the
 * settings menu beside them would offer a way out that has nothing to do with
 * where you are.
 *
 * The settings nav stays for install-wide settings, reached from the top nav.
 */
?>
<?php
/*
 * On a narrow screen the left column leaves the page and slides in from the
 * menu button in the header, as the report nav does (report.php,
 * owa.reportnav.js).
 */
?>
<table width="100%" cellpadding="0" cellspacing="0" class="owa_hierarchyPage">
    <TR>
        <TD valign="top" class="owa_reportLeftNavColumn">
            <div class="owa_reportNavRail" id="owa_reportNavRail">
                <div class="owa_reportNavRailHead">
                    <span class="owa_railLogo"><?php include( 'logo_image.php' );?></span>
                    <button type="button" class="owa_reportNavClose" aria-label="Hide the menu">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </div>
                <?php // The Property chooser, on a narrow screen (see report.php). ?>
                <div class="owa_railSiteControl"></div>
                <?php
                    $owa_rail   = $view->navRail( $view->report_nav, (string) $view->nav_property_id,
                        (array) $view->params, (string) $view->nav_access_site, true );
                    $owa_column = array( 'title' => 'Settings',
                        'groups' => $view->settingsColumn( $view->hierarchy_nav, (array) $view->params ) );
                    include( 'nav_shell.php' );
                ?>
            </div>
        </TD>
        <TD class="layout_subview owa_hierarchyContent" valign="top">
            <?php echo \OWA\Core\View::BANNER_SLOT; // the page's messages go here, not across the nav ?>
            <?php include('hierarchy_breadcrumb.php');?>
            <?php
                /*
                 * A Profile's page: its tag, how it observes and its details
                 * are tabs of one page, which the column names once.
                 */
                $owa_tabs = $view->profileTabs( $view->hierarchy_nav, (array) $view->params );
            ?>
            <?php if ( $owa_tabs ):?>
            <nav class="owa_pageTabs" aria-label="Profile">
                <?php foreach ( $owa_tabs as $owa_tab ):?>
                <a class="owa_pageTab<?php echo $owa_tab['current'] ? ' is-current' : '';?>"
                   <?php echo $owa_tab['current'] ? 'aria-current="page"' : '';?>
                   href="<?php $view->safeHref( $owa_tab['href'] );?>"><?php $view->out( $owa_tab['label'] );?></a>
                <?php endforeach;?>
            </nav>
            <?php endif;?>
            <?php echo $view->subview;?>
        </TD>
    </TR>
</table>
