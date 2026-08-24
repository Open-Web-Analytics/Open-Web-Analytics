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
                    $owa_current = $view->navLinkIsCurrent( $l, $view->params );

                    if ( ! $owa_current && array_key_exists( 'subgroup', $l ) ) {
                        foreach ( $l['subgroup'] as $owa_sub ) {
                            if ( $view->navLinkIsCurrent( $owa_sub, $view->params ) ) { $owa_current = true; break; }
                        }
                    }

                    if ( $owa_current ) { echo ' owa_current'; }
                    ?>">
                    <span class="owa_admin_nav_topmenu_toggle 
                    
                    <?php 
	                    
	                    if ( array_key_exists('subgroup', $l)) { 
		                    echo 'fa fa-caret-right'; 
		                } else { 
			                echo 'fa fa-blank';
			            }
			      
                    ?>"></span>
              
                    <span><i class="owa_nav_icon <?php $view->out( $l['icon_class']); ?>"></i><a class=" owa_admin_nav_topmenu_item_text" id="owa_admin_nav_topmenu_item_<?php echo $kl;?>" href="<?php echo $view->makeLink($view->navLinkParams($l), true);?>"><?php echo $l['anchortext'];?></a></span>
                    

                </div>


                <?php if (!empty($l['subgroup'])): ?>
                <div id="owa_admin_nav_subgroup_<?php echo $kl;?>" class="owa_admin_nav_subgroup">
                    <UL>
                        <?php foreach ($l['subgroup'] as $sgl): ?>
                        <?php if (!$view->getCurrentUser()->isCapable($sgl['priviledge'], $view->currentSiteId)) continue; ?>
                        <LI>
                            <div class="owa_admin_nav_subgroup_item ">
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

