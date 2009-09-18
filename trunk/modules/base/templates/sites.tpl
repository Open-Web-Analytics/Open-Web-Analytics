<DIV class="panel_headline"><?php echo $headline;?></DIV>
<DIV id="panel">
<P>Below is the list of Web Sites that can be tracked. A site must appear in this list 
if it is to be tracked/reported seperately.</P>

<fieldset>
	<legend>Tracked Web Sites <span class="legend_link">(<a href="<?php echo $this->makeLink(array('do' => 'base.sitesProfile'));?>">Add a Site</a>)<span></legend>


<TABLE size="100%" border="0" class="tablesorter">
	<thead>
	<TR>
		<TH>Name & Description</TH>
		<TH>Site Family</TH>
		<TH>Options</TH>
	</TR>
	</thead>
	<tbody>
	<?php foreach ($tracked_sites as $site => $value):?>
	<TR>
		<TD>
			<?php echo $value['name'];?><BR>
			<?php if (!empty($value['description'])):?>
			<span class="info_text"><?php echo $value['description'];?></span><BR>
			<?php endif;?>
			<span class="info_text"><a href="<?php echo $value['domain'];?>"><?php echo $value['domain'];?></a></span><BR>
		</TD>
		<TD>
			<?php echo $value['site_family'];?>
		</TD>
		<TD>
			<a href="<?php echo $this->makeLink(array('do' => 'base.sitesProfile', 'site_id' => $value['site_id'], 'edit' => true));?>">Edit</a> |
			<a href="<?php echo $this->makeLink(array('do' => 'base.sitesDelete', 'site_id' => $value['site_id']));?>">Delete</a> |
			<a href="<?php echo $this->makeLink(array('do' => 'base.sitesInvocation', 'site_id' => $value['site_id']));?>">Get Tags</a>
		</TD>
	
	</TR>
	<?php endforeach;?>
	</tbody>
</TABLE>

</fieldset>
</div>
