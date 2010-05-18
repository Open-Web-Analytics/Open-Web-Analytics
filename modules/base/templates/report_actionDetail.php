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

<script type="text/javascript">
	jQuery(function() {
		$("#owa-actions-workbook").tabs();
	});
</script>


<div id="owa-actions-workbook" class="owa-workbook">
	
	<ul>
		<li><a href="#actionsByLabel">Actions By Label</a></li>
		<li><a href="#actionsByDate">Actions By Date</a></li>
	</ul>
	
	<div id="actionsByLabel" class="owa_reportSectionContent">
	
		<div class="section_header">Action Labels</div>
		<div style="width:500px;" id="actionsByLabelExplorer"></div>
		
		<script>
		
		var aurl = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
														  'metrics' => 'actions,actionsValue', 
														  'dimensions' => 'actionLabel', 
														  'sort' => 'actions-', 
														  'resultsPerPage' => 25,
														  'format' => 'json',
														  'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId').'actionName=='.$actionName)), true);?>';
														  
		rsh = new OWA.resultSetExplorer('actionsByLabelExplorer');
		rsh.load(aurl, 'grid');
		</script>
		
	</div>
	
	<div id="actionsByDate" class="owa_reportSectionContent">
	
		<div class="section_header">Actions By Day</div>
		<div style="width:500px;" id="actionsByDateExplorer"></div>
		
		<script>
		
		var aurl2 = '<?php echo $this->makeApiLink(array('do' => 'getResultSet', 
														  'metrics' => 'actions,actionsValue', 
														  'dimensions' => 'date', 
														  'sort' => 'date-', 
														  'resultsPerPage' => 25,
														  'format' => 'json',
														  'constraints' => urlencode($this->substituteValue('siteId==%s,','siteId').'actionName=='.$actionName)), true);?>';
														  
		rsh2 = new OWA.resultSetExplorer('actionsByDateExplorer');
		rsh2.load(aurl2, 'grid');
		</script>
		
	</div>
	
</div>


