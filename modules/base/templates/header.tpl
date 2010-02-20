<div id="owa_header">
	<span class="owa_logo"><img src="<?php echo $this->makeImageLink('base/i/owa_logo_150w.jpg'); ?>" alt="Open Web Analytics"></span>
	<span class="owa_navigation">
			<UL>
				<LI><a href="<?php echo $this->makeLink(array('do' => 'base.reportDashboard'));?>">Reports</a></LI>
				<LI><a href="<?php echo $this->makeLink(array('do' => 'base.optionsGeneral'));?>">Settings</a></LI>
				<LI><a href="http://wiki.openwebanalytics.com">Help</a></LI>
				<LI><a href="http://trac.openwebanalytics.com">Report a Bug</a></LI>
				<?php if ($this->config['is_embedded'] == false):?>
				<LI>
					<?php if (owa_coreAPI::isCurrentUserAuthenticated()):?>
					<a href="<?php echo $this->makeLink(array('do' => 'base.logout'), false);?>">Logout</a>
					<?php else:?>
					<a href="<?php echo $this->makeLink(array('do' => 'base.loginForm'), false);?>">Login</a>
					<?php endif;?>
				</LI>
				<?php endif;?>
			</UL>
		
		<div class="post-nav"></div>
		<?php if (!empty($service_msg)): ?>
		<div class="owa_headerServiceMsg"><?php echo $service_msg; ?></div>
		<?php endif;?>

	</span>
			
	<?php $this->headerActions(); ?>
	
</div>