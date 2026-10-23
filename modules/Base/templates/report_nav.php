<?php /** @var \OWA\Core\ViewScope $view */ ?>
<div class="owa_admin_nav">

    <UL>
        <?php foreach ($view->links as $kl => $l): ?>
        <?php if (!$view->getCurrentUser()->isCapable($l['priviledge'], $view->currentSiteId)) { continue; } ?>
        <LI>
            <div class="owa_admin_nav_topmenu">

                <div class="owa_admin_nav_topmenu_item <?php
                    // Every report answers to do=base.report, so a link is only
                    // "current" when its reportId matches too -- comparing the
                    // action alone now highlights every report at once.
                    //
                    // As in the settings nav, only the screen you are on is
                    // marked (is-current). A group holding it is has-current,
                    // which opens the group (report.php) and draws nothing.
                    if ( $view->navLinkIsCurrent( $l, $view->params ) ) {
                        echo ' is-current';
                    } elseif ( array_key_exists( 'subgroup', $l ) ) {
                        foreach ( $l['subgroup'] as $owa_sub ) {
                            if ( $view->navLinkIsCurrent( $owa_sub, $view->params ) ) { echo ' has-current'; break; }
                        }
                    }
                    ?>">
              
                    <?php
                        // An entry that declares no icon gets the default: on a narrow
                        // screen the icon is all that shows of it (owa.reportnav.js).
                        $owa_icon = trim( (string) ( $l['icon_class'] ?? '' ) ) ?: \OWA\Core\Module::DEFAULT_NAV_ICON;
                    ?>
                    <span title="<?php $view->out( strip_tags( (string) $l['anchortext'] ) ); ?>"><i class="owa_nav_icon <?php $view->out( $owa_icon ); ?>" aria-hidden="true"></i><a class=" owa_admin_nav_topmenu_item_text" id="owa_admin_nav_topmenu_item_<?php echo $kl;?>" href="<?php echo $view->makeLink($view->navLinkParams($l), true);?>"><?php echo $l['anchortext'];?></a></span>
                    <?php if ( ! empty( $l['subgroup'] ) ): ?>
                    <?php
                    /*
                     * Right of the name: a chevron that points down while the
                     * group is closed and up while it is open (report.php).
                     */
                    ?>
                    <button type="button" class="owa_admin_nav_topmenu_toggle" aria-expanded="false"
                            aria-controls="owa_admin_nav_subgroup_<?php echo $kl;?>"
                            aria-label="<?php $view->out( strip_tags( (string) $l['anchortext'] ) ); ?> reports"><i class="fas fa-chevron-down" aria-hidden="true"></i></button>
                    <?php endif; ?>
                    

                </div>


                <?php if (!empty($l['subgroup'])): ?>
                <div id="owa_admin_nav_subgroup_<?php echo $kl;?>" class="owa_admin_nav_subgroup">
                    <UL>
                        <?php foreach ($l['subgroup'] as $sgl): ?>
                        <?php if (!$view->getCurrentUser()->isCapable($sgl['priviledge'], $view->currentSiteId)) continue; ?>
                        <LI>
                            <?php
                                /*
                                 * The report itself is marked, not only the
                                 * group holding it. The group tells a reader
                                 * roughly where they are; this is the line that
                                 * says which report they are actually looking
                                 * at, and it is what a group of eight entries
                                 * needs to be readable.
                                 */
                            ?>
                            <div class="owa_admin_nav_subgroup_item<?php
                                if ( $view->navLinkIsCurrent( $sgl, $view->params ) ) {
                                    echo ' is-current';
                                }
                            ?>">
                                <a href="<?php echo $view->makeLink($view->navLinkParams($sgl), true);?>"><?php echo $sgl['anchortext'];?></a>
                            </div>

                        </LI>
                        <?php endforeach;?>
                    </UL>
                </div>
                <?php endif; ?>
            </div>
        </LI>
        <?php endforeach;?>
    </UL>

</div>

