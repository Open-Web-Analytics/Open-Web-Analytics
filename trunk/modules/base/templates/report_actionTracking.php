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

</div>
<?php endif;?>

<?php if ($actionsByGroup->getDataRows()):?>
<div class="section_header">Actions By Group</div>
<div class="owa_reportSectionContent">

</div>
<?php endif;?>
