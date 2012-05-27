<div class="owa_admin_nav">
	
	<UL>
		<?php foreach ($links as $kl => $l): ?>
		<?php if (!$this->getCurrentUser()->isCapable($l['priviledge'], $currentSiteId)) { continue; } ?>
		<LI>
			<div class="owa_admin_nav_topmenu">
				
				<div class="owa_admin_nav_topmenu_item">
					<div class="owa_admin_nav_topmenu_toggle"></div>
					<div style="padding:5px;">
						<a id="owa_admin_nav_topmenu_item_<?php echo $kl;?>" href="<?php echo $this->makeLink(array('do' => $l['ref']), true);?>"><?php echo $l['anchortext'];?></a>
					</div>
					
				</div>
				
			
				<?php if (!empty($l['subgroup'])): ?>
				<div id="owa_admin_nav_subgroup_<?php echo $kl;?>" class="owa_admin_nav_subgroup">
					<UL>
						<?php foreach ($l['subgroup'] as $sgl): ?>
						<?php if (!$this->getCurrentUser()->isCapable($sgl['priviledge'], $currentSiteId)) continue; ?>
						<LI>
							<div class="owa_admin_nav_subgroup_item">
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

