<div class="panel_headline"><?=$headline?></div>
<div id="panel">

<? if (!empty($modules)): ?>

<table width="100%" id="module_roster" class="tablesorter">
	<thead>
	<TR>
		<TH>Module</TH>
		<TH>Version</TH>
		<TH>Description</TH>
		<th>Current Schema Version</th>
		<th>Required Schema Version</th>
		<th>Is Schema Up to Date?</th>
		<TH></TH>
	</TR>
	</thead>
	<tbody>
	<? foreach ($modules as $k => $v): ?>
	
	<TR>
		<TD><?=$v['display_name'];?></TD>
		<TD><?=$v['version'];?></TD>
		<TD><?=$v['description'];?></TD>
		<TD><?=$v['current_schema_version'];?></TD>
		<TD><?=$v['required_schema_version'];?></TD>
		<TD><?=$v['schema_uptodate'];?></TD>
		<TD class="">
		<?php if ($v['name'] != 'base'): ?>
		<? if ($v['status'] == 'active'): ?>
			<a href="<?=$this->makeLink(array('do' => 'base.moduleDeactivate', 'module' => $v['name']));?>">Deactivate</a>
		<? else: ?>
			<a href="<?=$this->makeLink(array('do' => 'base.moduleActivate', 'module' => $v['name']));?>">Activate</a>
		<? endif; ?>
		<?php endif;?>	
		
		</TD>
	</TR>
	
	<? endforeach; ?>
	</tbody>
</table>

<? else: ?>

There are no additional modules installed.

<? endif;?>

</div>