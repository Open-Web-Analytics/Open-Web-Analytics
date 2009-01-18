<div class="owa_admin_nav">
	
	<UL>
		<?php foreach ($links as $kl => $l): ?>
		<LI>
			<div class="owa_admin_nav_topmenu">
				
				<div class="owa_admin_nav_topmenu_item">
					<div class="owa_admin_nav_topmenu_toggle"></div>
					<div style="padding:5px;">
						<a id="owa_admin_nav_topmenu_item_<?=$kl;?>" href="<?=$this->makeLink(array('do' => $l['ref']), true);?>"><?=$l['anchortext'];?></a>
					</div>
					
				</div>
				
			
				<?php if (!empty($l['subgroup'])): ?>
				<div id="owa_admin_nav_subgroup_<?=$kl;?>" class="owa_admin_nav_subgroup">
					<UL>
						<?php foreach ($l['subgroup'] as $sgl): ?>
						<LI>
							<a href="<?=$this->makeLink(array('do' => $sgl['ref']), true);?>"><?=$sgl['anchortext'];?></a>
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

