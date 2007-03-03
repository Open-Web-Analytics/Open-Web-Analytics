<div class="panel_headline"><?=$headline?></div>
<div id="panel">

<? if (!empty($modules)): ?>

<table width="100%" id="module_roster">
	<TR>
		<TH>Module</TH>
		<TH>Version</TH>
		<TH>Description</TH>
		<TH></TH>
	</TR>
	
	<? foreach ($modules as $k => $v): ?>
	
	<TR>
		<TD><?=$v['display_name'];?></TD>
		<TD><?=$v['version'];?></TD>
		<TD><?=$v['description'];?></TD>
		<TD class="">
		
		<? if ($v['status'] == 'active'): ?>
			<a href="<?=$this->makeLink(array('do' => 'base.moduleDeactivate', 'module' => $v['name']));?>">Deactivate</a>
		<? else: ?>
			<a href="<?=$this->makeLink(array('do' => 'base.moduleActivate', 'module' => $v['name']));?>">Activate</a>
		<? endif; ?>	
		
		</TD>
	</TR>
	
	<? endforeach; ?>
	
</table>

<? else: ?>

There are no additional modules installed.

<? endif;?>

</div>