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

<div class="section_header">Action Labels</div>
<div class="owa_reportSectionContent">

	<div style="width:500px;" id="actionsByLabelExplorer"></div>
	<script>
	
	var aurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
													  'metrics' => 'actions,actionsValue', 
													  'dimensions' => 'actionLabel', 
													  'sort' => 'actions-', 
													  'resultsPerPage' => 25,
													  'format' => 'json',
													  'constraints' => urlencode('actionName=='.$actionName)), true);?>';
													  
	rsh = new OWA.resultSetExplorer('actionsByLabelExplorer');
	rsh.load(aurl, 'grid');
	</script>

<BR>
</div>