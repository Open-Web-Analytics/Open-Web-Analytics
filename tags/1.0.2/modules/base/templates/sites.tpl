<DIV class="panel_headline"><?=$headline;?></DIV>
<DIV id="panel">
<P>Below is the list of Web Sites that can be tracked. A site must appear in this list 
if it is to be tracked/reported seperately.</P>

<fieldset>
	<legend>Tracked Web Sites <span class="legend_link">(<a href="<?=$this->makeLink(array('view' => 'base.options', 'subview' => 'base.sitesAdd'));?>">Add a Site</a>)<span></legend>


<TABLE>
	<TR>
		<TH>Name & Description</TH>
		<TH>Site Family</TH>
		<TH>Options</TH>
	</TR>
<? foreach ($sites as $site =>$value):?>
	<TR>
	
		<TD>
			<?=$value['name'];?><BR>
			<span class="info_text"><?=$value['description'];?></span><BR>
			<span class="info_text"><a href="http://<?=$value['domain'];?>"><?=$value['domain'];?></a></span>
		</TD>
		
		<TD><?=$value['site_family'];?></TD>
		<TD nowrap>
			<a href="<?=$this->makeLink(array('view' => 'base.options', 'subview' => 'base.sitesEdit', 'site_id' => $value['site_id']));?>">Edit</a> |
			<a href="<?=$this->makeLink(array('action' => 'base.sitesDelete', 'site_id' => $value['site_id']));?>">Delete</a> |
			<a href="<?=$this->makeLink(array('view' => 'base.options', 'subview' => 'base.sitesInvocation', 'site_id' => $value['site_id']));?>">Get Tags</a>
		</TD>
	
	</TR>
<? endforeach;?>

</TABLE>

</fieldset>
</div>
