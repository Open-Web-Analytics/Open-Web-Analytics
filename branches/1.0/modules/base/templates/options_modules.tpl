<div class="panel_headline"><?php echo $headline?></div>
<div id="panel">

<?php if (!empty($modules)): ?>

<table width="100%" id="module_roster" class="management">
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
	<?php foreach ($modules as $k => $v): ?>
	
	<TR>
		<TD><?php echo $v['display_name'];?></TD>
		<TD><?php echo $v['version'];?></TD>
		<TD><?php echo $v['description'];?></TD>
		<TD><?php echo $v['current_schema_version'];?></TD>
		<TD><?php echo $v['required_schema_version'];?></TD>
		<TD><?php echo $v['schema_uptodate'];?></TD>
		<TD class="">
		<?php if ($v['name'] != 'base'): ?>
		<?php if ($v['status'] == 'active'): ?>
			<a href="<?php echo $this->makeLink(array('do' => 'base.moduleDeactivate', 'module' => $v['name']));?>">Deactivate</a>
		<?php else: ?>
			<a href="<?php echo $this->makeLink(array('do' => 'base.moduleActivate', 'module' => $v['name']));?>">Activate</a>
		<?php endif; ?>
		<?php endif;?>	
		
		</TD>
	</TR>
	
	<?php endforeach; ?>
	</tbody>
</table>

<?php else: ?>

There are no additional modules installed.

<?php endif;?>

</div>