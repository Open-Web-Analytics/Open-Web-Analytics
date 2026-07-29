<div class="owa_admin_nav">

    <UL>
        <?php foreach ($links as $kl => $l): ?>
        <?php if (!$this->getCurrentUser()->isCapable($l['priviledge'], $currentSiteId)) { continue; } ?>
        <LI>
            <div class="owa_admin_nav_topmenu">

                <div class="owa_admin_nav_topmenu_item <?php if ($l['ref'] === $params['do'] || ( array_key_exists('subgroup', $l) && in_array( $params['do'], array_column($l['subgroup'], 'ref')))) { echo ' owa_current';} ?>">
                    <span class="owa_admin_nav_topmenu_toggle 
                    
                    <?php 
	                    
	                    if ( array_key_exists('subgroup', $l)) { 
		                    echo 'fa fa-caret-right'; 
		                } else { 
			                echo 'fa fa-blank';
			            }
			      
                    ?>"></span>
              
                    <span><i class="owa_nav_icon <?php $this->out( $l['icon_class']); ?>"></i><a class=" owa_admin_nav_topmenu_item_text" id="owa_admin_nav_topmenu_item_<?php echo $kl;?>" href="<?php echo $this->makeLink(array('do' => $l['ref']), true);?>"><?php echo $l['anchortext'];?></a></span>
                    

                </div>


                <?php if (!empty($l['subgroup'])): ?>
                <div id="owa_admin_nav_subgroup_<?php echo $kl;?>" class="owa_admin_nav_subgroup">
                    <UL>
                        <?php foreach ($l['subgroup'] as $sgl): ?>
                        <?php if (!$this->getCurrentUser()->isCapable($sgl['priviledge'], $currentSiteId)) continue; ?>
                        <LI>
                            <div class="owa_admin_nav_subgroup_item ">
                                <a href="<?php echo $this->makeLink(array('do' => $sgl['ref']), true);?>"><?php echo $sgl['anchortext'];?></a>
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

