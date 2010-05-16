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

<div class="section_header">Actions by Name</div>
<div class="owa_reportSectionContent">

	<div style="width:500px;" id="actionsByNameExplorer"></div>
	<script>
	
	var aurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
													  'metrics' => 'actions', 
													  'dimensions' => 'actionName', 
													  'sort' => 'actions-', 
													  'resultsPerPage' => 5,
													  'format' => 'json'), true);?>';
													  
	rsh = new OWA.resultSetExplorer('actionsByNameExplorer');
	var link = '<?php echo $this->makeLink(array('do' => 'base.reportActionDetail', 'actionName' => '%s'), true);?>';
	rsh.addLinkToColumn('actionName', link, ['actionName']);
	rsh.load(aurl, 'grid');
	</script>

<BR>
</div>

<div class="section_header">Actions By Group</div>
<div class="owa_reportSectionContent">
	<div style="width:500px;" id="actionsByGroupExplorer"></div>
	<script>
	var url = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
												  'metrics' => 'actions', 
												  'dimensions' => 'actionGroup', 
												  'sort' => 'actions-', 
												  'resultsPerPage' => 5,
												  'format' => 'json'), true);?>';
												  
	rshre = new OWA.resultSetExplorer('actionsByGroupExplorer');
	rshre.load(url);
	</script>


</div>

