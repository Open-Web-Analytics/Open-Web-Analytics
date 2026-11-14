<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The error page's body. The message itself is in the wrapper's error banner
 * (View\Error); this carries only the way back, when there is one, and for a
 * signed-in user refused a page, the menu beside it.
 */
?>
<?php if ( $view->get( 'report_nav' ) ):?>
<table width="100%" cellpadding="0" cellspacing="0" class="owa_hierarchyPage">
    <TR>
        <TD valign="top" class="owa_reportLeftNavColumn">
            <div class="owa_reportNavRail" id="owa_reportNavRail">
                <div class="owa_reportNavRailHead">
                    <span class="owa_railLogo"><?php include( 'logo_image.php' );?></span>
                    <button type="button" class="owa_reportNavClose" aria-label="<?php $view->out( \OWA\Core\CoreAPI::t( 'Hide the menu' ) );?>">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </div>
                <?php
                    $owa_rail   = $view->navRail( $view->report_nav, (string) $view->nav_property_id,
                        array(), (string) $view->nav_access_site );
                    $owa_column = null;
                    include( 'nav_shell.php' );
                ?>
            </div>
        </TD>
        <TD valign="top" class="owa_hierarchyContent">
            <div class="owa_contentScroll">
            <main id="owa_main">
            <?php echo \OWA\Core\View::BANNER_SLOT; // the page's messages go here, not across the nav ?>
            <?php if ( $view->back_url ):?>
            <p class="owa_errorBack"><a href="<?php $view->out( $view->back_url );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Return to the previous screen' ) );?></a></p>
            <?php endif;?>
            </main>
            </div>
        </TD>
    </TR>
</table>
<?php elseif ( $view->back_url ):?>
<p class="owa_errorBack"><a href="<?php $view->out( $view->back_url );?>"><?php $view->out( \OWA\Core\CoreAPI::t( 'Return to the previous screen' ) );?></a></p>
<?php endif;?>
