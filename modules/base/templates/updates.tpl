<div style="width:800px; margin: 0px auto -1px auto;">
	<div class="" style="text-align:center;">
		<h1>Open Web Analytics Updater</h1> 
	</div>
	<br>	
	<div class="layout_subview" valign="top" style="text-align:left;">
	
		<h2>Some Modules need to create or update their database tables.</h2> 
		
		<P>Here is the list of modules that have updates that needs to be applied:</P>
		
		<P>
		<UL>
		
		<?php foreach ($modules as $k => $module): ?>
			
			<LI><?php echo $module; ?></LI>
			
		<?php endforeach;?>
		
		</UL>
		</P>
		<P><I>It is recommended that you backup your database before applying updates.</I></P>
		<BR>
		
		<P>
		<a href="<?php echo $this->makeLink(array('do' => 'base.updatesApply'));?>"><span class="owa-button">Apply updates</span></a>
		</P>
	
	</div>

</div>



