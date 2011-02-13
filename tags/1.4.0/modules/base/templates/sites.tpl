<DIV class="panel_headline"><?php echo $headline;?></DIV>
<DIV id="panel">
<P>Below is the list of Web Sites that can be tracked. A site must appear in this list 
if it is to be tracked/reported separately.</P>

<fieldset>
	<legend>Tracked Web Sites <span class="legend_link">(<a href="<?php echo $this->makeLink(array('do' => 'base.sitesProfile'));?>">Add a Site</a>)<span></legend>


<TABLE width="100%" border="0" class="management">
	<thead>
	<TR>
		<TH>Name & Description</TH>
		
		<TH>Options</TH>
	</TR>
	</thead>
	<tbody>
	<?php foreach ($tracked_sites as $site => $value):?>
	<TR>
		<TD>
			<span style="font-size:14px; font-weight:bold;">
				<a href="<?php echo $this->makeLink( array('do' => 'base.reportDashboard', 'siteId' => $value['site_id'] ), false );?>"><?php $this->out( $value['name'] );?></a>
			</span><BR>
			<?php if (!empty($value['description'])):?>
			<span class="info_text"><?php $this->out( $value['description'] );?></span><BR>
			<?php endif;?>
			<span class="info_text"><?php $this->out( $value['domain'] );?></span><BR>
		</TD>
		
		<TD>
			<a href="<?php echo $this->makeLink( array('do' => 'base.sitesProfile', 'siteId' => $value['site_id'], 'edit' => true ) );?>">Edit</a> |
			<a href="<?php echo $this->makeLink( array('do' => 'base.sitesDelete', 'siteId' => $value['site_id'] ), false, false, false, true );?>">Delete</a> |
			<a href="<?php echo $this->makeLink( array('do' => 'base.sitesInvocation', 'siteId' => $value['site_id'] ) );?>">Get Tracking Code</a> | 
			<a href="<?php echo $this->makeLink( array('do' => 'base.optionsGoals', 'siteId' => $value['site_id'] ) );?>">Goals</a>
		</TD>
	
	</TR>
	<?php endforeach;?>
	</tbody>
</TABLE>

</fieldset>
</div>
