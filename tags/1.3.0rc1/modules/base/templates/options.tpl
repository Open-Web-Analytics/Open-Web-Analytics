<div>

<table id="layout_panels" cellpadding="0" cellspacing="0">
	<TR>
		<TD colspan="2" class="headline">
			<?php echo $headline;?>
		</TD>
	</TR>
	<TR>
		<TD colspan="2">
			<P>Open Web Analytics has several configuration options that can be set using the controls below. Once changes are made click the save button to save the configuration to the database. To learn more about configuring OWA, visit the <a href="http://wiki.openwebanalytics.com">OWA Wiki</a></P>		
		</TD>
	</TR>
	<TR>
		<TD valign="top" id="nav_left">
		
			<?php foreach ($panels as $group => $items):?>
			
				<H4><?php echo $group;?></H4>
					<UL>
					<?php foreach ($items as $k => $v):?>
						<?php if ($v['view']):?>
						<LI><a href="<?php echo $this->makeLink(array('view' => 'base.options', 'subview' => $v['view']));?>"><?php echo $v['anchortext'];?></a></LI>
						<?php else: ?>
						<LI><a href="<?php echo $this->makeLink(array('do' => $v['do']));?>"><?php echo $v['anchortext'];?></a></LI>
						<?php endif; ?>
					<?php endforeach;?>
					</UL>
			<?php endforeach;?>
		</TD>
		<TD class="layout_subview"><?php echo $subview;?></TD>
	</TR>

</table>
</div>

<script>
// Bind event handlers
jQuery(document).ready(function(){   
	jQuery.tablesorter.defaults.widgets = ['zebra'];
	jQuery('.tablesorter').tablesorter();
});
</script>
