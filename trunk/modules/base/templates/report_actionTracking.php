<div class="owa_reportSectionHeader">Metrics</div>
<div class="owa_reportSectionContent">
	
	<table cellpadding="0" cellspacing="0" width="100%">
	<tr>
		<td valign="top">
		<?php foreach($aggregates->aggregates as $row):?>
			<div class="owa_metricInfobox">
				<p class="owa_metricInfoboxLabel"><?php echo $row['label'];?></p>
				<p class="owa_metricInfoboxLargeNumber"><?php echo $row['value'];?></p>	
			</div>
		<?php endforeach;?>
		</td>
	</tr>
</table>

</div>

<?php if ($actionsByName->getDataRows()):?>
<div class="section_header">Actions by Name</div>
<div class="owa_reportSectionContent">
<?php echo $actionsByName->resultSetToJson();?>
<?php //echo $this->makePaginationFromResultSet($actionsByName);?>
<table id="grid"></table>
<div id="pjmap"></div>
<script>
var data = <?php echo $actionsByName->resultSetToJson();?>

rsh = new OWA.resultSetExplorer();
rsh.dom_id = '#grid';
rsh.getResultSet();
//rsh.setResultSet(data);
rsh.addAllRowsToGrid();
rsh.refreshGrid();
</script>

<BR>
</div>
<?php endif;?>

<?php if ($actionsByGroup->getDataRows()):?>
<div class="section_header">Actions By Group</div>
<div class="owa_reportSectionContent">

</div>
<?php endif;?>

<script>
tableToGrid('.owa_dataGrid', {height:300, width:300});
</script>